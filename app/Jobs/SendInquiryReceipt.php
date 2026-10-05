<?php

namespace App\Jobs;

use App\Mail\InquiryReceipt;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\MailboxVerification;
use App\Support\Audit;
use App\Support\PublicIntake;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendInquiryReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $verificationId) {}

    public function backoff(): array
    {
        return [10, 30];
    }

    public function failed(?\Throwable $exception): void
    {
        MailboxVerification::whereKey($this->verificationId)->where('transport_state', 'queued')->update(['transport_state' => 'failed']);
    }

    public function handle(): void
    {
        if (config('operations.restore_lockdown')) {
            return;
        }
        $claim = DB::transaction(function (): ?array {
            $candidate = MailboxVerification::find($this->verificationId);
            if (! $candidate) {
                return null;
            }
            $inquiry = Inquiry::whereKey($candidate->inquiry_id)->lockForUpdate()->firstOrFail();
            $record = MailboxVerification::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($record->invalidated_at || $record->transport_state !== 'queued' || $record->email !== ($inquiry->public_contact['email'] ?? null)) {
                return null;
            }
            if (! PublicIntake::transportAvailable(CompanySetting::current())) {
                $record->update(['transport_state' => 'unavailable']);

                return null;
            }
            $token = bin2hex(random_bytes(32));
            $record->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(config('public-intake.verification_expiry_hours')), 'transport_state' => 'dispatching', 'claimed_at' => now()]);

            return [$record, $token, $inquiry->reference];
        }, 3);
        if (! $claim) {
            return;
        }
        [$record, $token, $reference] = $claim;
        try {
            Mail::mailer(config('public-intake.receipt_mailer'))->to($record->email)->send(new InquiryReceipt($reference, $token, $record->template_body));
            $state = 'transport_submitted';
        } catch (\Throwable $exception) {
            $state = 'failed';
        }
        DB::transaction(function () use ($record, $state): void {
            $current = MailboxVerification::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $current->update(['transport_state' => $state, 'transport_submitted_at' => $state === 'transport_submitted' ? now() : null]);
            Audit::record('Receipt transport outcome', $current->inquiry, Audit::snapshot($current->inquiry), details: ['transport' => ['before' => 'dispatching', 'after' => $state]], systemActor: 'System / receipt transport');
        });
    }
}
