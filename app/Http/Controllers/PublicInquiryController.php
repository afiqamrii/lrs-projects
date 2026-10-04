<?php

namespace App\Http\Controllers;

use App\Actions\RequestMailboxVerification;
use App\Actions\SubmitPublicInquiry;
use App\Http\Requests\PublicInquiryRequest;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\MailboxVerification;
use App\Support\Audit;
use App\Support\PublicIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PublicInquiryController extends Controller
{
    public function create(Request $request): View
    {
        $settings = CompanySetting::current();

        return view('public.request', ['settings' => $settings, 'intakeToken' => $settings->public_intake_enabled ? PublicIntake::issue($request) : null]);
    }

    public function draft(PublicInquiryRequest $request): RedirectResponse
    {
        if (PublicIntake::key($request)->inquiry_id) {
            $request->session()->put('public_receipt_inquiry', PublicIntake::key($request)->inquiry_id);

            return to_route('public-inquiries.receipt');
        }
        $key = $request->validated('add_row');
        $input = $request->except('files');
        if (count($input['shipment'][$key] ?? []) >= 20) {
            return to_route('public-inquiries.create')->withInput($input)->withErrors(['shipment.'.$key => 'A maximum of 20 rows is supported.']);
        }
        $input['shipment'][$key][] = [];

        return to_route('public-inquiries.create')->withInput($input)->with('warning', 'Row added. Entered details are preserved; select attachments again before submitting.');
    }

    public function store(PublicInquiryRequest $request, SubmitPublicInquiry $submit): RedirectResponse
    {
        try {
            $inquiry = $submit->handle($request);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return to_route('public-inquiries.create')->withInput($request->except('files'))->withErrors(['submission' => 'We could not finish saving your request. Your entered details are preserved. Please reselect attachments and try again; the same form key prevents a duplicate case.']);
        }
        $request->session()->put('public_receipt_inquiry', $inquiry->id);

        return to_route('public-inquiries.receipt');
    }

    private function receiptInquiry(Request $request): Inquiry
    {
        return Inquiry::whereKey($request->session()->get('public_receipt_inquiry'))
            ->whereHas('publicSubmission', fn ($query) => $query->where('session_hash', PublicIntake::sessionHash($request)))->firstOrFail();
    }

    public function receipt(Request $request): View
    {
        $inquiry = $this->receiptInquiry($request);

        return view('public.receipt', ['settings' => CompanySetting::current(), 'inquiry' => $inquiry, 'verification' => $inquiry->mailboxVerifications()->first(), 'canResend' => $inquiry->public_contact['email'] === mb_strtolower(trim($inquiry->publicSubmission->snapshot['contact']['email']))]);
    }

    public function resend(Request $request, RequestMailboxVerification $send): RedirectResponse
    {
        $inquiry = $this->receiptInquiry($request);
        abort_unless($inquiry->public_contact['email'] === mb_strtolower(trim($inquiry->publicSubmission->snapshot['contact']['email'])), 403);
        if (! $inquiry->mailboxConfirmed()) {
            $send->handle($inquiry, true);
        }

        return to_route('public-inquiries.receipt')->with('status', 'Confirmation request recorded. See the current email status below.');
    }

    public function confirmation(): View
    {
        return view('public.confirm', ['settings' => CompanySetting::current(), 'state' => null]);
    }

    public function confirm(Request $request): View
    {
        $data = $request->validate(['confirmation_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        $state = DB::transaction(function () use ($data): string {
            $hash = hash('sha256', $data['confirmation_token']);
            $candidate = MailboxVerification::where('token_hash', $hash)->first();
            if (! $candidate) {
                return 'invalid';
            }
            $inquiry = Inquiry::whereKey($candidate->inquiry_id)->lockForUpdate()->firstOrFail();
            $record = MailboxVerification::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($record->invalidated_at || $record->email !== ($inquiry->public_contact['email'] ?? null)) {
                return 'invalid';
            }
            if ($record->confirmed_at) {
                return 'already_confirmed';
            }
            if (! $record->expires_at || $record->expires_at->isPast()) {
                return 'expired';
            }
            $record->update(['confirmed_at' => now()]);
            Audit::record('Submitted mailbox access confirmed', $inquiry, Audit::snapshot($inquiry), details: ['mailbox_check' => ['before' => 'Unverified', 'after' => 'Mailbox access confirmed only']], systemActor: 'System / mailbox confirmation');

            return 'confirmed';
        }, 3);

        return view('public.confirm', ['settings' => CompanySetting::current(), 'state' => $state]);
    }
}
