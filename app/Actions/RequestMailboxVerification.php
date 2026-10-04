<?php

namespace App\Actions;

use App\Jobs\SendInquiryReceipt;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\MailboxVerification;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use App\Support\PublicIntake;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestMailboxVerification
{
    public function handle(Inquiry $inquiry, bool $publicSession = false, ?int $expected = null): MailboxVerification
    {
        return DB::transaction(function () use ($inquiry, $publicSession, $expected): MailboxVerification {
            $record = $expected === null ? Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail() : InquiryWorkflow::locked($inquiry, $expected);
            if ($publicSession && $record->public_contact['email'] !== mb_strtolower(trim($record->publicSubmission->snapshot['contact']['email']))) {
                throw ValidationException::withMessages(['email' => 'Staff have updated the working contact. Contact the company for another confirmation request.']);
            }
            $settings = CompanySetting::current();
            $record->mailboxVerifications()->whereNull('invalidated_at')->update(['invalidated_at' => now()]);
            $state = ! $settings->receipt_mail_enabled ? 'disabled' : (PublicIntake::transportAvailable($settings) ? 'queued' : 'unavailable');
            $verification = $record->mailboxVerifications()->create(['email' => $record->public_contact['email'], 'transport_state' => $state, 'template_body' => $settings->receipt_mail_body]);
            Audit::record('Mailbox confirmation requested', $record, Audit::snapshot($record), details: ['transport' => ['before' => null, 'after' => $state]], systemActor: $publicSession || ! auth()->check() ? 'System / public submission' : null);
            if ($state === 'queued') {
                DB::afterCommit(function () use ($verification): void {
                    try {
                        SendInquiryReceipt::dispatch($verification->id);
                    } catch (\Throwable $exception) {
                        $verification->update(['transport_state' => 'failed']);
                    }
                });
            }

            return $verification;
        });
    }
}
