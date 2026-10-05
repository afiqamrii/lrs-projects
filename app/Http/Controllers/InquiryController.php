<?php

namespace App\Http\Controllers;

use App\Actions\RecordCommunication;
use App\Actions\SaveInquiry;
use App\Actions\TransitionInquiry;
use App\Http\Requests\CommunicationRequest;
use App\Http\Requests\InquiryRequest;
use App\Http\Requests\InquiryTransitionRequest;
use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\ShipmentVersion;
use App\Models\User;
use App\Support\InquiryJourney;
use App\Support\Shipment;
use App\Support\WorkspaceData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InquiryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Inquiry::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', Rule::in(array_keys(Inquiry::STATUSES))], 'owner' => ['nullable', 'integer', 'exists:users,id'], 'priority' => ['nullable', Rule::in(['normal', 'urgent'])], 'client' => ['nullable', 'integer', 'exists:clients,id'], 'overdue' => ['nullable', Rule::in(['1'])], 'source' => ['nullable', Rule::in(array_keys(Inquiry::CHANNELS))], 'data' => ['nullable', Rule::in(['real', 'samples', 'fixtures', 'all'])], 'unassigned' => ['nullable', Rule::in(['1'])]]);
        $filters['data'] ??= WorkspaceData::preview() ? 'samples' : 'real';
        $metricQuery = Inquiry::query();
        if ($filters['data'] === 'samples') {
            $metricQuery->where('sample_set', WorkspaceData::SAMPLE_SET);
        }
        if ($filters['data'] !== 'all') {
            $metricQuery->where('is_demo', in_array($filters['data'], ['fixtures', 'samples'], true));
        }
        $query = Inquiry::with('client', 'contact', 'owner')->withCount(['documents' => fn ($q) => $q->where('is_archived', false)]);
        if ($filters['data'] === 'samples') {
            $query->where('sample_set', WorkspaceData::SAMPLE_SET);
        }
        if ($q = $filters['q'] ?? null) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
            $query->where(fn ($b) => $b->where('reference', 'ilike', $pattern)->orWhere('title', 'ilike', $pattern)->orWhere('public_contact->email', 'ilike', $pattern)->orWhere('public_contact->company', 'ilike', $pattern)->orWhereHas('client', fn ($c) => $c->where('company_name', 'ilike', $pattern)));
        }
        foreach (['status' => 'status', 'owner' => 'owner_id', 'priority' => 'priority', 'client' => 'client_id', 'source' => 'source_channel'] as $key => $field) {
            if ($value = $filters[$key] ?? null) {
                $query->where($field, $value);
            }
        }
        if ($filters['data'] !== 'all') {
            $query->where('is_demo', in_array($filters['data'], ['fixtures', 'samples'], true));
        }
        if ($filters['unassigned'] ?? null) {
            $query->whereNull('owner_id');
        }
        if ($filters['overdue'] ?? null) {
            $query->where('status', '!=', 'closed')->where('response_due_at', '<', now());
        }

        return view('inquiries.index', ['inquiries' => $query->orderByRaw("CASE WHEN status='closed' THEN 1 ELSE 0 END")->orderBy('response_due_at')->orderByDesc('id')->paginate(12)->withQueryString(), 'filters' => $filters, 'owners' => User::orderBy('name')->get(), 'clients' => Client::orderBy('company_name')->get(), 'review' => (clone $metricQuery)->where('status', 'needs_review')->count(), 'waiting' => (clone $metricQuery)->where('status', 'needs_client_information')->count(), 'overdue' => (clone $metricQuery)->where('status', '!=', 'closed')->where('response_due_at', '<', now())->count(), 'total' => (clone $metricQuery)->count()]);
    }

    private function form(Inquiry $inquiry): View
    {
        $clients = Client::where('is_active', true)->when($inquiry->exists, fn ($q) => $q->orWhere('id', $inquiry->client_id))->with('contacts')->orderBy('company_name')->get();

        return view('inquiries.form', ['inquiry' => $inquiry, 'clients' => $clients, 'owners' => User::where('is_active', true)->orderBy('name')->get(), 'gaps' => $inquiry->exists ? ($inquiry->gaps() + Shipment::warnings($inquiry->shipment)) : [], 'packageRows' => max(1, min(20, (int) request('package_rows', max(1, count(old('shipment.packages', $inquiry->shipment['packages'] ?? [])))))), 'containerRows' => max(1, min(20, (int) request('container_rows', max(1, count(old('shipment.containers', $inquiry->shipment['containers'] ?? []))))))]);
    }

    public function create(): View
    {
        Gate::authorize('create', Inquiry::class);

        return $this->form(new Inquiry(['client_id' => request('client_id'), 'owner_id' => auth()->id(), 'priority' => 'normal', 'received_at' => now(), 'source_channel' => 'email', 'shipment' => Shipment::normalize([])]));
    }

    public function edit(Inquiry $inquiry): View
    {
        Gate::authorize('update', $inquiry);

        return $this->form($inquiry);
    }

    public function show(Request $request, Inquiry $inquiry): View
    {
        Gate::authorize('view', $inquiry);
        $section = $request->validate(['section' => ['nullable', Rule::in(['overview', 'shipment', 'documents', 'activity'])]])['section'] ?? 'overview';
        $inquiry->load('publicSubmission', 'mailboxVerifications', 'client', 'contact', 'owner', 'versions', 'documents', 'clarifications', 'communications.documents');

        return view('inquiries.show', ['inquiry' => $inquiry, 'section' => $section, 'journey' => InquiryJourney::current($inquiry), 'gaps' => $inquiry->gaps(), 'totals' => Shipment::totals($inquiry->shipment), 'activity' => AuditEntry::where('inquiry_id', $inquiry->id)->latest('id')->paginate(15)->withQueryString()]);
    }

    private function rowRedirect(InquiryRequest $request, ?Inquiry $inquiry = null): ?RedirectResponse
    {
        if (! $request->filled('add_row')) {
            return null;
        }
        $key = $request->input('add_row');
        $rows = min(20, count($request->input('shipment.'.$key, [])) + 1);

        return to_route($inquiry ? 'inquiries.edit' : 'inquiries.create', array_filter(['inquiry' => $inquiry?->id, $key === 'packages' ? 'package_rows' : 'container_rows' => $rows]))->withInput();
    }

    public function store(InquiryRequest $request, SaveInquiry $save): RedirectResponse
    {
        return $this->rowRedirect($request) ?? to_route('inquiries.show', $save->handle($request->validated()))->with('status', 'Inquiry captured as a draft. Review the missing information before confirmation.');
    }

    public function update(InquiryRequest $request, Inquiry $inquiry, SaveInquiry $save): RedirectResponse
    {
        if ($redirect = $this->rowRedirect($request, $inquiry)) {
            return $redirect;
        } $record = $save->handle($request->validated(), $inquiry);

        return to_route('inquiries.show', $record)->with('status', 'Working inquiry saved. Confirmed shipment history is preserved.');
    }

    public function transition(InquiryTransitionRequest $request, Inquiry $inquiry, TransitionInquiry $transition): RedirectResponse
    {
        $record = $transition->handle($inquiry, $request->validated());

        return to_route('inquiries.show', $record)->with('status', Inquiry::STATUSES[$record->status].'. No email, quote or booking was created.');
    }

    public function communicate(CommunicationRequest $request, Inquiry $inquiry, RecordCommunication $record): RedirectResponse
    {
        $record->handle($inquiry, $request->validated());

        return to_route('inquiries.show', ['inquiry' => $inquiry, 'section' => 'activity'])->with('status', 'Manual communication recorded. Review shipment requirements against the customer’s answer.');
    }

    public function version(Inquiry $inquiry, ShipmentVersion $version): View
    {
        Gate::authorize('view', $inquiry);

        return view('inquiries.version', compact('inquiry', 'version'));
    }
}
