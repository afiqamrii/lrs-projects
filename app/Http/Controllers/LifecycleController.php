<?php

namespace App\Http\Controllers;

use App\Actions\ManageLifecycle;
use App\Actions\ManageOperationalMail;
use App\Http\Requests\ClientDecisionRequest;
use App\Http\Requests\HandoffEventRequest;
use App\Http\Requests\HandoffPolicyRequest;
use App\Http\Requests\HandoffRequest;
use App\Http\Requests\OperationalMessageRequest;
use App\Http\Requests\VendorConfirmationRequest;
use App\Jobs\SyncMailbox;
use App\Models\BookingHandoff;
use App\Models\ClientDecision;
use App\Models\ClientQuotationRevision;
use App\Models\HandoffApproval;
use App\Models\HandoffEvent;
use App\Models\HandoffPolicy;
use App\Models\HandoffRevision;
use App\Models\Inquiry;
use App\Models\MailDispatch;
use App\Models\MailMessage;
use App\Models\OperationalMessage;
use App\Models\User;
use App\Models\VendorConfirmation;
use App\Models\VendorReconfirmation;
use App\Support\GmailFixture;
use App\Support\HandoffPdf;
use App\Support\LifecycleEligibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LifecycleController extends Controller
{
    private function authorizeCase(Inquiry $i, ?object $child = null): void
    {
        Gate::authorize('update', $i);
        if ($child) {
            $id = match (true) {
                $child instanceof ClientQuotationRevision => $child->quotation->inquiry_id,
                $child instanceof HandoffRevision => $child->handoff->inquiry_id,
                $child instanceof HandoffApproval => $child->revision->handoff->inquiry_id,default => $child->inquiry_id
            };
            abort_unless($id === $i->id, 404);
        }
    }

    private function source(Request $r, Inquiry $i): ?MailMessage
    {
        $id = $r->validate(['message' => 'nullable|integer'])['message'] ?? null;

        return $id ? MailMessage::whereKey($id)->where('inquiry_id', $i->id)->where('direction', 'incoming')->firstOrFail() : null;
    }

    public function index(Inquiry $inquiry): View
    {
        $this->authorizeCase($inquiry);
        $status = LifecycleEligibility::statuses($inquiry);

        return view('lifecycle.index', ['inquiry' => $inquiry, 'status' => $status, 'quotes' => $inquiry->clientQuotation?->revisions ?? collect(),
            'decisions' => ClientDecision::where('inquiry_id', $inquiry->id)->latest('id')->limit(50)->get(),
            'requests' => VendorReconfirmation::where('inquiry_id', $inquiry->id)->latest('id')->get(),
            'confirmations' => VendorConfirmation::where('inquiry_id', $inquiry->id)->latest('id')->limit(50)->get(),
            'handoffs' => BookingHandoff::where('inquiry_id', $inquiry->id)->first()?->revisions()->orderByDesc('number')->get() ?? collect(),
            'events' => HandoffEvent::where('inquiry_id', $inquiry->id)->latest('id')->limit(50)->get()]);
    }

    public function decision(Request $r, Inquiry $inquiry, ClientQuotationRevision $revision): View
    {
        $this->authorizeCase($inquiry, $revision);
        $message = $this->source($r, $inquiry);

        return view('lifecycle.decision', ['inquiry' => $inquiry, 'revision' => $revision, 'message' => $message, 'latest' => LifecycleEligibility::decision($revision),
            'history' => ClientDecision::where('client_quotation_revision_id', $revision->id)->orderByDesc('number')->get(), 'contacts' => $inquiry->client?->contacts()->where('is_active', true)->get() ?? collect(), 'documents' => $inquiry->documents()->where('is_archived', false)->get()]);
    }

    public function saveDecision(ClientDecisionRequest $r, Inquiry $inquiry, ClientQuotationRevision $revision, ManageLifecycle $a): RedirectResponse
    {
        $this->authorizeCase($inquiry, $revision);
        $d = $a->decision($revision, $r->user(), $r->validated());

        return to_route('lifecycle.index', $inquiry)->with('status', $d->outcome === 'accepted' ? 'Exact client acceptance recorded. Vendor reconfirmation requires separate review. Booking remains unconfirmed.' : 'Client response retained as '.str_replace('_', ' ', $d->outcome).'. Corresponding reminders stopped.');
    }

    public function vendor(Request $r, Inquiry $inquiry, VendorReconfirmation $reconfirmation, ManageOperationalMail $mail): View
    {
        $this->authorizeCase($inquiry, $reconfirmation);

        return view('lifecycle.vendor', ['inquiry' => $inquiry, 'request' => $reconfirmation, 'current' => $reconfirmation->current(), 'history' => $reconfirmation->confirmations()->orderByDesc('number')->get(),
            'message' => $this->source($r, $inquiry), 'contacts' => $reconfirmation->selection->revision->offer->vendor->contacts()->where('is_active', true)->get(), 'documents' => $inquiry->documents()->where('is_archived', false)->get(),
            'messages' => $mail->messages($reconfirmation)->get(), 'defaults' => $mail->defaults($reconfirmation), 'reasons' => LifecycleEligibility::requestReasons($reconfirmation)]);
    }

    public function saveVendor(VendorConfirmationRequest $r, Inquiry $inquiry, VendorReconfirmation $reconfirmation, ManageLifecycle $a): RedirectResponse
    {
        $this->authorizeCase($inquiry, $reconfirmation);
        $v = $a->confirmation($reconfirmation, $r->user(), $r->validated());

        return to_route('lifecycle.vendor', [$inquiry, $reconfirmation])->with('status', 'Vendor evidence retained as '.ucfirst($v->status).'. '.($v->status === 'changed' ? 'Review the changed offer, replace client pricing and renew client agreement.' : 'Handoff and booking remain separate.'));
    }

    public function handoff(Request $r, Inquiry $inquiry): View
    {
        $this->authorizeCase($inquiry);
        $handoff = BookingHandoff::where('inquiry_id', $inquiry->id)->first();
        $current = $handoff?->current();
        $evidence = $current?->snapshot['evidence'] ?? [];
        $message = $this->source($r, $inquiry);
        $vendorEmails = $current ? MailMessage::where('inquiry_id', $inquiry->id)->where('direction', 'incoming')->where('match_state', 'matched')->whereNotNull('response_reviewed_at')
            ->whereHas('operationalMessage', fn ($op) => $op->where('handoff_revision_id', $current->id)->orWhere('vendor_reconfirmation_id', $current->confirmation?->vendor_reconfirmation_id ?? 0))->latest('id')->get() : collect();

        return view('lifecycle.handoff', ['inquiry' => $inquiry, 'current' => $current, 'evidence' => $evidence, 'message' => $message, 'vendorEmails' => $vendorEmails, 'ready' => LifecycleEligibility::readiness($inquiry, $evidence),
            'history' => $handoff?->revisions()->orderByDesc('number')->get() ?? collect(), 'staff' => User::where('is_active', true)->whereIn('role', ['admin', 'agent'])->get(), 'documents' => $inquiry->documents()->where('is_archived', false)->get(),
            'events' => $current?->approval ? HandoffEvent::where('handoff_approval_id', $current->approval->id)->latest('id')->get() : collect(),
            'messages' => $current ? app(ManageOperationalMail::class)->messages($current)->get() : collect()]);
    }

    public function saveHandoff(HandoffRequest $r, Inquiry $inquiry, ManageLifecycle $a): RedirectResponse
    {
        $this->authorizeCase($inquiry);
        $h = $a->saveHandoff($inquiry, $r->user(), $r->validated());

        return to_route('lifecycle.handoff', $inquiry)->with('status', 'Private handoff revision '.$h->number.' saved. '.($h->state === 'ready' ? 'Ready for separate exact approval.' : 'Missing prerequisites still block approval.'));
    }

    public function approve(Request $r, Inquiry $inquiry, HandoffRevision $revision, ManageLifecycle $a): RedirectResponse
    {
        $this->authorizeCase($inquiry, $revision);
        $d = $r->validate(['digest' => 'required|string|size:64', 'confirm' => 'accepted']);
        $a->approveHandoff($revision, $r->user(), $d['digest']);

        return to_route('lifecycle.handoff', $inquiry)->with('status', 'Exact internal handoff approved. Booking remains unconfirmed until actual vendor evidence is recorded.');
    }

    public function pdf(Inquiry $inquiry, HandoffRevision $revision): Response
    {
        $this->authorizeCase($inquiry, $revision);
        $record = $revision->approval ?? $revision;

        return response(HandoffPdf::bytes($record), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$inquiry->reference.'-private-handoff-v'.$revision->number.'.pdf"']);
    }

    public function event(HandoffEventRequest $r, Inquiry $inquiry, HandoffApproval $approval, ManageLifecycle $a): RedirectResponse
    {
        $this->authorizeCase($inquiry, $approval);
        $a->event($approval, $r->user(), $r->validated());

        return to_route('lifecycle.handoff', $inquiry)->with('status', 'Actual '.str_replace('_', ' ', $r->validated('kind')).' evidence recorded. Earlier evidence remains preserved.');
    }

    public function saveMessage(OperationalMessageRequest $r, Inquiry $inquiry, string $kind, int $parent, ManageOperationalMail $a): RedirectResponse
    {
        abort_unless(in_array($kind, ['reconfirmation', 'booking'], true), 404);
        $p = $kind === 'reconfirmation' ? VendorReconfirmation::findOrFail($parent) : HandoffRevision::findOrFail($parent);
        $this->authorizeCase($inquiry, $p);
        $m = $a->save($p, $r->user(), $r->validated());

        return to_route('lifecycle.message', [$inquiry, $m])->with('status', 'Editable message saved as a new immutable version. Review exact content and actual envelope before approval.');
    }

    public function message(Request $r, Inquiry $inquiry, OperationalMessage $message, ManageOperationalMail $a): View
    {
        $this->authorizeCase($inquiry, $message);
        $preview = null;
        $reasons = [];
        try {
            $preview = $a->preview($message, $r->user());
        } catch (ValidationException $e) {
            $reasons = array_merge(...array_values($e->errors()));
        }
        $parent = $message->kind === 'reconfirmation' ? $message->reconfirmation : $message->handoff;
        $defaults = $a->defaults($parent);
        $defaults = array_replace($defaults, ['to_contact_id' => $message->content['to']['id'], 'cc_contact_ids' => array_column($message->content['cc'], 'id'), 'subject' => $message->content['subject'], 'body' => $message->content['body'], 'reason' => $message->reason, 'document_ids' => array_column($message->content['manifest'], 'document_id')]);

        return view('lifecycle.message', compact('inquiry', 'message', 'preview', 'reasons', 'parent', 'defaults') + ['contacts' => ($message->kind === 'reconfirmation' ? $parent : $parent->confirmation->request)->selection->revision->offer->vendor->contacts()->where('is_active', true)->get(), 'documents' => $inquiry->documents()->where('is_archived', false)->get()]);
    }

    public function approveMessage(Request $r, Inquiry $inquiry, OperationalMessage $message, ManageOperationalMail $a): RedirectResponse
    {
        $this->authorizeCase($inquiry, $message);
        $d = $r->validate(['digest' => 'required|string|size:64', 'confirm' => 'accepted']);
        $approval = $a->approve($message, $r->user(), $d['digest']);

        return to_route('mail.preview', ['operations', $approval->id])->with('status', 'Exact vendor message approved. Authorize the shared mailbox envelope and explicitly enqueue to send.');
    }

    public function bookingMessage(Inquiry $inquiry, ManageOperationalMail $mail): View
    {
        $this->authorizeCase($inquiry);
        $parent = BookingHandoff::where('inquiry_id', $inquiry->id)->first()?->current();
        abort_unless($parent?->confirmation, 404);
        $mail->basis($parent);

        return view('lifecycle.compose', ['inquiry' => $inquiry, 'parent' => $parent, 'kind' => 'booking', 'defaults' => $mail->defaults($parent), 'contacts' => $parent->confirmation->request->selection->revision->offer->vendor->contacts()->where('is_active', true)->get(), 'documents' => $inquiry->documents()->where('is_archived', false)->get()]);
    }

    public function fixtureResponse(MailDispatch $dispatch): RedirectResponse
    {
        Gate::authorize('manage-company');
        abort_unless(app()->environment('local', 'testing') && config('mailbox.demo_enabled') && $dispatch->is_demo && $dispatch->envelope->mailbox->provider === 'gmail' && $dispatch->envelope->mailbox->usable(), 404);
        $envelope = $dispatch->envelope;
        $content = $envelope->snapshot['content'];
        $body = null;
        if ($approval = $envelope->clientQuotationApproval) {
            $q = $approval->revision;
            $body = 'We accept '.$q->quotation->reference.' revision '.$q->number.' at '.$q->pricing['currency'].' '.$q->pricing['total'].'. We agree unconditionally to this exact service scope, inclusions, exclusions and terms. Client reference SIM-PO-1025-017.';
        } elseif ($op = $envelope->operationalMessageApproval?->message) {
            $request = $op->kind === 'reconfirmation' ? $op->reconfirmation : $op->handoff->confirmation->request;
            $s = $request->selection->snapshot['shipment']['shipment'];
            $body = 'We confirm the complete selected vendor rate of '.$request->selection->revision->currency.' '.$request->selection->revision->complete_total.', exact scope and charges. Equipment/capacity is available for '.$s['cargo_ready_date'].'. Arrival '.($s['arrival_date'] ?? 'not stated').'. No outstanding conditions.';
            if ($op->kind === 'booking') {
                $body .= ' Actual simulated vendor booking reference SIM-OCEAN-1025-093.';
            }
        }
        abort_unless($body !== null, 422);
        app(GmailFixture::class)->incoming($envelope->mailbox, null, $dispatch, $body);
        foreach ($envelope->mailbox->folders()->where('identity_hash', $envelope->identity_hash)->where('enabled', true)->where('kind', 'incoming')->get() as $folder) {
            SyncMailbox::dispatch($folder->id)->onQueue('mail');
        }

        return to_route('inquiries.mail', $envelope->inquiry_id)->with('status', 'Fictional reply queued through Gmail fixture sync. Review its original evidence and record the exact decision separately.');
    }

    public function settings(): View
    {
        Gate::authorize('manage-company');
        $policy = HandoffPolicy::current();

        return view('lifecycle.settings', ['policy' => $policy, 'history' => HandoffPolicy::orderByDesc('number')->get()]);
    }

    public function policy(HandoffPolicyRequest $r, ManageLifecycle $a): RedirectResponse
    {
        Gate::authorize('manage-company');
        $a->policy($r->user(), $r->validated());

        return to_route('settings.handoff')->with('status', 'Company requirements approved as a new policy version. Existing handoffs need a new revision if requirements changed.');
    }
}
