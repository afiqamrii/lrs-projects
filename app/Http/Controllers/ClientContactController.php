<?php

namespace App\Http\Controllers;

use App\Actions\SaveClientContact;
use App\Http\Requests\ClientContactRequest;
use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClientContactController extends Controller
{
    public function create(Client $client): View
    {
        Gate::authorize('update', $client);

        return view('clients.contact', ['client' => $client, 'contact' => new ClientContact]);
    }

    public function edit(Client $client, ClientContact $contact): View
    {
        Gate::authorize('update', $client);

        return view('clients.contact', compact('client', 'contact'));
    }

    public function store(ClientContactRequest $request, Client $client, SaveClientContact $save): RedirectResponse
    {
        $save->handle($client, $request->validated());

        return to_route('clients.show', $client)->with('status', 'Client contact added.');
    }

    public function update(ClientContactRequest $request, Client $client, ClientContact $contact, SaveClientContact $save): RedirectResponse
    {
        $save->handle($client, $request->validated(), $contact);

        return to_route('clients.show', $client)->with('status', 'Client contact updated.');
    }
}
