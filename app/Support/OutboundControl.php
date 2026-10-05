<?php

namespace App\Support;

use App\Actions\MailOutbox;
use App\Models\CompanySetting;
use App\Models\MailDispatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OutboundControl
{
    public static function assertAvailable(?int $epoch = null, bool $lock = false): void
    {
        $settings = $lock ? CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail() : CompanySetting::current();
        if (config('operations.restore_lockdown') || $settings->outbound_paused || ($epoch !== null && $epoch !== $settings->outbound_epoch)) {
            throw ValidationException::withMessages(['release' => 'Outgoing business mail is paused or this request predates an emergency pause. Admin must resume, then staff must explicitly review and recover this exact message. Reminders require a new activation.']);
        }
    }

    public function change(User $staff, bool $pause, int $epoch, string $reason): void
    {
        Gate::forUser($staff)->authorize('manage-company');
        DB::transaction(function () use ($staff, $pause, $epoch, $reason): void {
            $settings = CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($settings->outbound_epoch !== $epoch || $settings->outbound_paused === $pause || (! $pause && config('operations.restore_lockdown'))) {
                throw ValidationException::withMessages(['control' => 'The control changed or this restored environment is locked down. Reload before making an explicit decision.']);
            }
            $before = $settings->outbound_paused;
            $settings->forceFill(['outbound_paused' => $pause, 'outbound_epoch' => $pause ? $epoch + 1 : $epoch, 'outbound_reason' => $reason, 'outbound_changed_by' => $staff->id, 'outbound_changed_at' => now()])->save();
            Audit::record($pause ? 'Emergency outbound pause' : 'Explicit outbound resume', $settings, actor: $staff, details: ['outbound_paused' => ['before' => $before, 'after' => $pause], 'reason' => ['before' => null, 'after' => $reason]]);
        });
    }

    public static function holdDispatch(MailDispatch $dispatch): void
    {
        try {
            self::assertAvailable($dispatch->outbound_epoch);
        } catch (ValidationException $e) {
            $ambiguous = $dispatch->submission_started_at !== null || ($dispatch->draft_started_at !== null && ! $dispatch->provider_draft_id);
            $dispatch->update(['status' => $ambiguous ? 'uncertain' : 'failed', 'lease' => null, 'lease_until' => null, 'next_attempt_at' => null, 'last_error' => $e->errors()['release'][0]]);
            app(MailOutbox::class)->event($dispatch, 'emergency_hold', $ambiguous ? 'Emergency hold preserved ambiguous provider evidence; reconcile only, never resend.' : 'Pre-submission dispatch held; explicit current recovery required. No new provider submission.');
        }
    }
}
