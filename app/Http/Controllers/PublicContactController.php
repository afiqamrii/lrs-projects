<?php

namespace App\Http\Controllers;

use App\Actions\RequestMailboxVerification;
use App\Actions\ResolvePublicContact;
use App\Http\Requests\ResolvePublicContactRequest;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Inquiry;
use App\Models\MailMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PublicContactController extends Controller
{
    public function edit(Inquiry $inquiry): View
    {
        Gate::authorize('update', $inquiry);
        abort_unless($inquiry->publicSubmission || MailMessage::where('inquiry_id', $inquiry->id)->where('direction', 'incoming')->exists(), 404);
        $email = $inquiry->public_contact['email'] ?? $inquiry->contact?->email;
        $matches = ClientContact::with('client')->where('email', $email)->limit(10)->get();
        $duplicates = Inquiry::where('id', '!=', $inquiry->id)->where(fn ($query) => $query->where('public_contact->email', $email)->orWhereHas('contact', fn ($contact) => $contact->where('email', $email)))->latest('id')->limit(5)->get();

        return view('inquiries.public-contact', ['inquiry' => $inquiry, 'clients' => Client::where('is_active', true)->with('contacts')->orderBy('company_name')->get(), 'matches' => $matches, 'duplicates' => $duplicates]);
    }

    public function update(ResolvePublicContactRequest $request, Inquiry $inquiry, ResolvePublicContact $resolve): RedirectResponse
    {
        $resolve->handle($inquiry, $request->validated());

        return to_route('inquiries.show', $inquiry)->with('status', 'Staff client assessment saved. Original customer evidence is preserved; mailbox confirmation is separate.');
    }

    public function resend(Request $request, Inquiry $inquiry, RequestMailboxVerification $send): RedirectResponse
    {
        Gate::authorize('update', $inquiry);
        abort_unless($inquiry->publicSubmission, 404);
        if (! $inquiry->mailboxConfirmed()) {
            $send->handle($inquiry, expected: (int) $request->validate(['lock_version' => ['required', 'integer', 'min:0']])['lock_version']);
        }

        return to_route('inquiries.public-contact.edit', $inquiry)->with('status', 'Confirmation request recorded. Transport acceptance does not confirm delivery.');
    }
}
