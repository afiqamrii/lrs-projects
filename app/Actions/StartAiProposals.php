<?php

namespace App\Actions;

use App\Jobs\RequestAiProposals;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Inquiry;
use App\Models\User;
use App\Support\AiSources;
use App\Support\AiUsage;
use App\Support\Processing;
use App\Support\ProposalSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StartAiProposals
{
    public function handle(Inquiry $inquiry, User $staff, array $ids, string $expectedHash, string $scopeHash, bool $retry = false, ?string $reason = null): AiRun
    {
        Gate::forUser($staff)->authorize('update', $inquiry);

        return DB::transaction(function () use ($inquiry, $staff, $ids, $expectedHash, $scopeHash, $retry, $reason): AiRun {
            $settings = AiSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $record = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $record->documents()->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($expectedHash, $record->snapshotHash())) {
                Processing::fail('The working shipment changed. Review the current shipment before authorizing another request.');
            }
            $sources = AiSources::selected($record, $ids);
            $rates = $settings->configuration;
            $scope = AiUsage::scope($sources, $settings);
            if (! hash_equals($scopeHash, $scope)) {
                Processing::fail('Sources, model or pricing changed since the preview. Review the updated scope before authorizing payment.');
            }
            $identity = Processing::hash(['scope' => $scope, 'shipment' => $record->snapshotHash()]);
            $previous = AiRun::where('inquiry_id', $record->id)->where('identity', $identity)->where('is_demo', false)->latest('generation')->first();
            if ($previous && (in_array($previous->state, ['queued', 'processing'], true) || (! $retry && $previous->state === 'needs_review'))) {
                return $previous;
            }
            if ($previous && (! $retry || ! $reason || $previous->cost_uncertain)) {
                Processing::fail('A paid retry must be explicit with a reason. Reconcile uncertain provider outcomes with Admin before retrying.');
            }
            $unavailable = AiUsage::unavailable($settings);
            if ($unavailable) {
                Processing::fail(implode(' ', $unavailable));
            }
            $bound = AiUsage::bound($sources);
            if ($bound > config('ai.input_tokens')) {
                Processing::fail('The conservative input-token bound exceeds this run limit. Select fewer source passages.');
            }
            $reservation = AiUsage::estimate($sources, $settings);
            if ($reservation === null) {
                Processing::fail('No hard monetary estimate is available. Admin must configure dated rates and caps first.');
            }
            $day = AiUsage::reserve($record, $settings, $reservation);
            $run = AiRun::create([
                'inquiry_id' => $record->id, 'requested_by' => $staff->id, 'budget_day_id' => $day->id,
                'identity' => $identity, 'generation' => ($previous?->generation ?? 0) + 1, 'shipment_hash' => $record->snapshotHash(),
                'shipment_revision' => $record->shipment_revision, 'model' => $settings->model,
                'prompt_version' => config('ai.prompt_version'), 'schema_version' => config('ai.schema_version'),
                'settings' => $rates + ['max_output_tokens' => config('ai.output_tokens'), 'prompt_hash' => hash('sha256', ProposalSchema::prompt()), 'schema_hash' => Processing::hash(ProposalSchema::schema())], 'sources' => $sources, 'working_snapshot' => $record->snapshot(),
                'input_characters' => mb_strlen(AiSources::input($sources)), 'input_bound' => $bound, 'reservation' => $reservation,
                'state' => 'queued', 'retry_reason' => $reason,
            ]);
            RequestAiProposals::dispatch($run->id)->onQueue('ai')->afterCommit();

            return $run;
        });
    }
}
