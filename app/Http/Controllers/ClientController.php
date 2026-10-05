<?php

namespace App\Http\Controllers;

use App\Actions\SaveClient;
use App\Http\Requests\ClientRequest;
use App\Models\AuditEntry;
use App\Models\Client;
use App\Support\Audit;
use App\Support\WorkspaceData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Client::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', Rule::in(['active', 'archived'])], 'contact' => ['nullable', Rule::in(['ready', 'missing'])]]);
        if (WorkspaceData::preview() && ! $request->has('status')) {
            $filters['status'] = 'active';
        }
        $query = Client::with('primaryContact')->withCount('inquiries');
        if ($q = $filters['q'] ?? null) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
            $query->where(fn ($b) => $b->where('company_name', 'ilike', $pattern)->orWhere('reference_identifier', 'ilike', $pattern)->orWhereHas('contacts', fn ($c) => $c->where('name', 'ilike', $pattern)->orWhere('email', 'ilike', $pattern)));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }
        if ($contact = $filters['contact'] ?? null) {
            $contact === 'ready' ? $query->whereHas('contacts', fn ($c) => $c->where('is_active', true)) : $query->whereDoesntHave('contacts', fn ($c) => $c->where('is_active', true));
        }

        return view('clients.index', ['clients' => $query->orderByRaw('lower(company_name)')->orderBy('id')->paginate(12)->withQueryString(), 'filters' => $filters, 'total' => Client::count(), 'active' => Client::where('is_active', true)->count(), 'missing' => Client::whereDoesntHave('contacts', fn ($c) => $c->where('is_active', true))->count()]);
    }

    public function create(): View
    {
        Gate::authorize('create', Client::class);

        return view('clients.form', ['client' => new Client]);
    }

    public function edit(Client $client): View
    {
        Gate::authorize('update', $client);

        return view('clients.form', compact('client'));
    }

    public function show(Client $client): View
    {
        Gate::authorize('view', $client);
        $client->load('contacts', 'primaryContact');

        return view('clients.show', ['client' => $client, 'duplicates' => Client::where('id', '!=', $client->id)->whereRaw('lower(company_name) = ?', [mb_strtolower($client->company_name)])->get(), 'inquiries' => $client->inquiries()->with('owner')->latest('id')->paginate(8), 'activity' => AuditEntry::where('client_id', $client->id)->latest('id')->limit(10)->get()]);
    }

    public function store(ClientRequest $request, SaveClient $save): RedirectResponse
    {
        return to_route('clients.show', $save->handle($request->validated()))->with('status', 'Client created. Add an inquiry when your team is ready.');
    }

    public function update(ClientRequest $request, Client $client, SaveClient $save): RedirectResponse
    {
        $save->handle($request->validated(), $client);

        return to_route('clients.show', $client)->with('status', 'Client details updated.');
    }

    public function statusForm(Client $client): View
    {
        Gate::authorize('update', $client);

        return view('clients.status', compact('client'));
    }

    public function status(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('update', $client);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($client, $data): void {
            $record = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $before = Audit::snapshot($record);
            $record->update($data);
            Audit::record($record->is_active ? 'Client reactivated' : 'Client archived', $record, $before);
        });

        return to_route('clients.show', $client)->with('status', $data['is_active'] ? 'Client reactivated.' : 'Client archived. Contacts and inquiry history are preserved.');
    }
}
