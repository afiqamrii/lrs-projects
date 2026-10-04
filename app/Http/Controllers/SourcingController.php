<?php

namespace App\Http\Controllers;

use App\Actions\ManageRfq;
use App\Actions\PrepareRfqs;
use App\Actions\StartRfqWording;
use App\Http\Requests\RfqActionRequest;
use App\Http\Requests\RfqRequest;
use App\Http\Requests\SelectVendorsRequest;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AuditEntry;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\Rfq;
use App\Models\SourcingRound;
use App\Models\Vendor;
use App\Support\AiUsage;
use App\Support\Processing;
use App\Support\RfqContent;
use App\Support\RfqEligibility;
use App\Support\RfqWording;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SourcingController extends Controller
{
    public function index(Request $request, Inquiry $inquiry): View
    {
        Gate::authorize('view', $inquiry);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'type' => ['nullable', Rule::in(array_keys(Vendor::TYPES))], 'service' => ['nullable', Rule::in(Vendor::SERVICES)]]);
        $query = Vendor::where('is_active', true)->with('contacts');
        if ($q = $filters['q'] ?? null) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $query->where(fn ($builder) => $builder->where('company_name', 'ilike', $pattern)->orWhere('coverage', 'ilike', $pattern)->orWhereHas('contacts', fn ($contacts) => $contacts->where('name', 'ilike', $pattern)->orWhere('email', 'ilike', $pattern)));
        }
        if ($type = $filters['type'] ?? null) {
            $query->where('type', $type);
        }
        if ($service = $filters['service'] ?? null) {
            $query->whereJsonContains('services', $service);
        }
        $rounds = SourcingRound::where('inquiry_id', $inquiry->id)->with('version', 'rfqs.vendor', 'rfqs.revisions.approval.dispatch')->latest('id')->get();
        $current = $rounds->first(fn (SourcingRound $round): bool => $round->version->number === $inquiry->shipment_revision);

        return view('sourcing.index', ['inquiry' => $inquiry, 'rounds' => $rounds, 'current' => $current, 'filters' => $filters, 'vendors' => $query->orderBy('company_name')->paginate(12)->withQueryString(), 'gaps' => RfqEligibility::readiness($inquiry)]);
    }

    public function select(SelectVendorsRequest $request, Inquiry $inquiry, PrepareRfqs $prepare): RedirectResponse
    {
        $prepare->handle($inquiry, $request->user(), $request->validated('vendor_ids'), (int) $request->validated('lock_version'));

        return to_route('inquiries.sourcing', $inquiry)->with('status', 'Separate vendor drafts prepared. Existing selections were reused; no messages were sent.');
    }

    private function authorizeCase(Inquiry $inquiry, Rfq $rfq): void
    {
        abort_unless($rfq->inquiry_id === $inquiry->id, 404);
        Gate::authorize('view', $rfq);
    }

    private function data(Inquiry $inquiry, Rfq $rfq, ?int $number = null): array
    {
        $this->authorizeCase($inquiry, $rfq);
        $revision = $number ? $rfq->revisions()->where('number', $number)->firstOrFail() : $rfq->current();
        $snapshot = $revision->approval?->snapshot ?? RfqContent::snapshot($revision);
        $previous = $rfq->revisions()->where('number', '<', $revision->number)->latest('number')->first();
        $changes = [];
        if ($previous) {
            foreach ($revision->payload as $key => $value) {
                if (Processing::hash(['v' => $value]) !== Processing::hash(['v' => $previous->payload[$key] ?? null])) {
                    $changes[$key] = ['before' => $previous->payload[$key] ?? null, 'after' => $value];
                }
            }
        }

        return ['inquiry' => $inquiry, 'rfq' => $rfq, 'revision' => $revision, 'snapshot' => $snapshot, 'digest' => Processing::hash($snapshot), 'reasons' => RfqEligibility::reasons($revision), 'releaseReasons' => RfqEligibility::reasons($revision, true), 'changes' => $changes, 'history' => $rfq->revisions()->with('approval.dispatch')->latest('number')->get(), 'documents' => $inquiry->documents()->get(), 'contacts' => $rfq->vendor->contacts()->get(), 'limitations' => RfqEligibility::limitations($rfq), 'runs' => $revision->aiRuns()->latest('id')->get(), 'aiReasons' => AiUsage::unavailable(AiSetting::current()), 'activity' => AuditEntry::where('record_type', 'Rfq')->where('record_id', $rfq->id)->latest('id')->get()];
    }

    public function edit(Inquiry $inquiry, Rfq $rfq): View
    {
        return view('sourcing.edit', $this->data($inquiry, $rfq));
    }

    public function save(RfqRequest $request, Inquiry $inquiry, Rfq $rfq, ManageRfq $manage): RedirectResponse
    {
        $this->authorizeCase($inquiry, $rfq);
        $manage->save($rfq, $request->user(), $request->validated());

        return to_route('rfqs.edit', [$inquiry, $rfq])->with('status', 'Draft saved as a preserved revision. Review the exact content before approval.');
    }

    public function review(Request $request, Inquiry $inquiry, Rfq $rfq): View
    {
        $data = $request->validate(['revision' => ['nullable', 'integer', 'min:1']]);

        return view('sourcing.review', $this->data($inquiry, $rfq, isset($data['revision']) ? (int) $data['revision'] : null));
    }

    public function approve(RfqActionRequest $request, Inquiry $inquiry, Rfq $rfq, ManageRfq $manage): RedirectResponse
    {
        $this->authorizeCase($inquiry, $rfq);
        $manage->approve($rfq, $request->user(), (int) $request->validated('expected_revision'), $request->validated('digest'));

        return to_route('rfqs.output', [$inquiry, $rfq])->with('status', 'Exact revision approved — not sent.');
    }

    public function state(RfqActionRequest $request, Inquiry $inquiry, Rfq $rfq, ManageRfq $manage): RedirectResponse
    {
        $this->authorizeCase($inquiry, $rfq);
        $manage->state($rfq, $request->user(), (int) $request->validated('expected_revision'), $request->validated('target'), $request->validated('reason'));

        return to_route('rfqs.review', [$inquiry, $rfq])->with('status', 'Review state recorded. History and any external communication evidence are preserved.');
    }

    public function output(Inquiry $inquiry, Rfq $rfq): View
    {
        $this->authorizeCase($inquiry, $rfq);
        DB::transaction(function () use ($rfq): void {
            $record = app(ManageRfq::class)->locked($rfq, auth()->user(), $rfq->current_number);
            RfqEligibility::assert($record->current(), true);
        });

        return view('sourcing.output', $this->data($inquiry, $rfq));
    }

    public function attachment(Inquiry $inquiry, Rfq $rfq, int $document): BinaryFileResponse
    {
        $this->authorizeCase($inquiry, $rfq);
        $file = DB::transaction(function () use ($rfq, $document): InquiryDocument {
            $record = app(ManageRfq::class)->locked($rfq, auth()->user(), $rfq->current_number);
            $revision = $record->current();
            RfqEligibility::assert($revision, true);
            abort_unless(in_array($document, array_column($revision->approval->snapshot['manifest'], 'document_id'), true), 404);

            return $record->inquiry->documents()->whereKey($document)->firstOrFail();
        });

        return response()->download(Storage::disk('inquiry_documents')->path($file->storage_path), $file->original_name, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"])->setPrivate();
    }

    public function manual(RfqActionRequest $request, Inquiry $inquiry, Rfq $rfq, ManageRfq $manage): RedirectResponse
    {
        $this->authorizeCase($inquiry, $rfq);
        $manage->manual($rfq, $request->user(), $request->validated());

        return to_route('rfqs.output', [$inquiry, $rfq])->with('status', 'Manually recorded as sent. This is your declaration, not provider-confirmed delivery or reading.');
    }

    public function scope(Inquiry $inquiry, Rfq $rfq): View
    {
        $this->authorizeCase($inquiry, $rfq);
        $revision = $rfq->current();
        $sources = RfqWording::sources($revision);
        $settings = AiSetting::current();

        return view('sourcing.ai-scope', $this->data($inquiry, $rfq) + ['input' => RfqWording::input($sources), 'scopeHash' => AiUsage::scope($sources, $settings, 'rfq_wording'), 'bound' => AiUsage::bound($sources, 'rfq_wording'), 'estimate' => AiUsage::estimate($sources, $settings, 'rfq_wording'), 'settings' => $settings]);
    }

    public function requestWording(Request $request, Inquiry $inquiry, Rfq $rfq, StartRfqWording $start): RedirectResponse
    {
        $this->authorizeCase($inquiry, $rfq);
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1'], 'scope_hash' => ['required', 'string', 'size:64'], 'authorize_paid' => ['accepted'], 'retry' => ['nullable', 'boolean'], 'reason' => ['nullable', 'string', 'max:1000', 'required_if:retry,1']]);
        $start->handle($rfq, $request->user(), (int) $data['expected_revision'], $data['scope_hash'], $request->boolean('retry'), $data['reason'] ?? null);

        return to_route('rfqs.edit', [$inquiry, $rfq])->with('status', 'Optional wording queued or unchanged result reused. Refresh to review; no request fields were changed.');
    }

    public function applyWording(Request $request, Inquiry $inquiry, Rfq $rfq, AiRun $run, StartRfqWording $start): RedirectResponse
    {
        $this->authorizeCase($inquiry, $rfq);
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1'], 'reviewed_wording' => ['accepted']]);
        $start->apply($rfq, $run, $request->user(), (int) $data['expected_revision']);

        return to_route('rfqs.edit', [$inquiry, $rfq])->with('status', 'Reviewed wording applied to a new draft revision. Confirmed facts, recipients and attachments were preserved.');
    }
}
