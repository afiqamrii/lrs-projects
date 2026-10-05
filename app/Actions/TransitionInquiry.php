<?php

namespace App\Actions;

use App\Models\Inquiry;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionInquiry
{
    public function handle(Inquiry $inquiry, array $data): Inquiry
    {
        return DB::transaction(function () use ($inquiry, $data): Inquiry {
            $record = InquiryWorkflow::locked($inquiry, (int) $data['lock_version']);
            $target = $data['target'];
            if (! in_array($target, InquiryWorkflow::TRANSITIONS[$record->status], true)) {
                throw ValidationException::withMessages(['target' => 'That transition is unavailable from '.Inquiry::STATUSES[$record->status].'. Reload the workspace to see valid actions.']);
            }
            $before = Audit::snapshot($record);
            $details = [];
            if ($target === 'ready_for_sourcing') {
                if ($gaps = $record->gaps()) {
                    throw ValidationException::withMessages($gaps);
                }
                $hash = $record->snapshotHash();
                $version = $record->versions()->where('number', $record->shipment_revision)->first();
                if ($version && ! hash_equals($version->snapshot_hash, $hash)) {
                    throw ValidationException::withMessages(['shipment' => 'The selected contact or shipment changed after confirmation. Save a working revision before confirming again.']);
                }
                $record->versions()->firstOrCreate(['number' => $record->shipment_revision], ['snapshot' => $record->snapshot(), 'snapshot_hash' => $hash, 'reviewer_id' => auth()->id(), 'reviewer_name' => auth()->user()->name, 'confirmed_at' => now()]);
                $details = ['confirmed_revision' => ['before' => null, 'after' => $record->shipment_revision], 'reviewer' => ['before' => null, 'after' => auth()->user()->name]];
            }
            $record->status = in_array($target, ['resume', 'reopen'], true) ? 'needs_review' : $target;
            $record->status_reason = in_array($target, ['on_hold', 'closed'], true) ? $data['reason'] : null;
            $record->lock_version++;
            $record->save();
            if (in_array($target, ['on_hold', 'closed', 'reopen', 'resume'], true)) {
                app(ManageLifecycle::class)->stop($record, 'Inquiry status transition: '.$target.' · '.($data['reason'] ?? 'Renew eligibility checks.'), auth()->user());
            }
            Audit::record(match ($target) {
                'ready_for_sourcing' => 'Shipment confirmed','resume' => 'Inquiry resumed for review','reopen' => 'Inquiry reopened for review',default => 'Inquiry status changed'
            }, $record, $before, null, null, $details + ($target === 'reopen' ? ['reopen_reason' => ['before' => null, 'after' => $data['reason']]] : []));

            return $record;
        });
    }
}
