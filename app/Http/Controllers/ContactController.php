<?php

namespace App\Http\Controllers;

use App\Actions\SaveContact;
use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function create(Vendor $vendor): View
    {
        Gate::authorize('update', $vendor);

        return view('contacts.form', ['vendor' => $vendor, 'contact' => new Contact]);
    }

    public function edit(Vendor $vendor, Contact $contact): View
    {
        Gate::authorize('update', $vendor);

        return view('contacts.form', compact('vendor', 'contact'));
    }

    public function store(ContactRequest $request, Vendor $vendor, SaveContact $save): RedirectResponse
    {
        $save->handle($vendor, $request->validated());

        return redirect()->route('vendors.show', $vendor)->with('status', 'Contact added.');
    }

    public function update(ContactRequest $request, Vendor $vendor, Contact $contact, SaveContact $save): RedirectResponse
    {
        $save->handle($vendor, $request->validated(), $contact);

        return redirect()->route('vendors.show', $vendor)->with('status', 'Contact updated.');
    }
}
