<?php

namespace App\Actions;

use App\Jobs\RequestAiProposals;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Rfq;
use App\Models\User;
use App\Support\AiUsage;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use App\Support\Processing;
use App\Support\RfqEligibility;
use App\Support\RfqWording;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class StartRfqWording
{
    public function handle(Rfq $rfq, User $staff, int $expected, string $scope, bool $retry = false, ?string $reason = null): AiRun
    {
        return DB::transaction(function () use ($rfq, $staff, $expected, $scope, $retry, $reason): AiRun {
            $settings = AiSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $record = app(ManageRfq::class)->locked($rfq, $staff, $expected);
            $revision = $record->current();
            if (! in_array($revision->status, ['draft', 'needs_approval', 'changes_requested'], true) || RfqEligibility::readiness($record->inquiry)) {
                Processing::fail('Optional AI wording requires a current unapproved draft of a confirmed inquiry.');
            }
            $sources = RfqWording::sources($revision);
            if (! hash_equals($scope, AiUsage::scope($sources, $settings, 'rfq_wording'))) {
                Processing::fail('The wording, model or rates changed. Preview the current paid scope again.');
            }
            $identity = Processing::hash(['purpose' => 'rfq_wording', 'scope' => $scope, 'revision_id' => $revision->id]);
            $previous = AiRun::where('inquiry_id', $record->inquiry_id)->where('identity', $identity)->latest('generation')->first();
            if ($previous && (in_array($previous->state, ['queued', 'processing'], true) || $previous->state === 'needs_review')) {
                return $previous;
            }
            if ($previous && (! $retry || ! $reason || $previous->cost_uncertain)) {
                Processing::fail('An explicit reason is required for retry. Reconcile uncertain usage with Admin first.');
            }
            $reusable = AiRun::where('inquiry_id', $record->inquiry_id)->where('purpose', 'rfq_wording')->where('state', 'needs_review')->where('cost_uncertain', false)->where('model', $settings->model)->latest('id')->get()->first(function (AiRun $candidate) use ($sources, $settings): bool {
                return Processing::hash(array_diff_key($candidate->sources, array_flip(['revision_id']))) === Processing::hash(array_diff_key($sources, array_flip(['revision_id'])))
                    && ($candidate->settings['prompt_hash'] ?? null) === hash('sha256', RfqWording::prompt())
                    && ($candidate->settings['schema_hash'] ?? null) === Processing::hash(RfqWording::schema())
                    && Processing::hash(array_diff_key($candidate->settings, array_flip(['max_output_tokens', 'prompt_hash', 'schema_hash']))) === Processing::hash($settings->configuration);
            });
            if ($reusable) {
                return AiRun::create(['inquiry_id' => $record->inquiry_id, 'requested_by' => $staff->id, 'purpose' => 'rfq_wording', 'rfq_revision_id' => $revision->id, 'identity' => $identity, 'generation' => ($previous?->generation ?? 0) + 1, 'shipment_hash' => $record->inquiry->snapshotHash(), 'shipment_revision' => $record->inquiry->shipment_revision, 'model' => $settings->model, 'prompt_version' => config('rfq.prompt_version'), 'schema_version' => config('rfq.schema_version'), 'settings' => $reusable->settings, 'sources' => $sources, 'working_snapshot' => ['rfq_revision_id' => $revision->id, 'reused_from_run_id' => $reusable->id], 'input_characters' => mb_strlen(RfqWording::input($sources)), 'input_bound' => AiUsage::bound($sources, 'rfq_wording'), 'state' => 'needs_review', 'result' => $reusable->result, 'attempts' => 0, 'reservation' => '0', 'estimated_cost' => '0', 'completed_at' => now()]);
            }
            if ($reasons = AiUsage::unavailable($settings)) {
                Processing::fail(implode(' ', $reasons));
            }
            $bound = AiUsage::bound($sources, 'rfq_wording');
            if ($bound > config('ai.input_tokens') || mb_strlen(RfqWording::input($sources)) > config('ai.input_chars')) {
                Processing::fail('This wording scope exceeds configured input bounds. Shorten permitted draft wording.');
            }
            $reservation = AiUsage::estimate($sources, $settings, 'rfq_wording');
            if ($reservation === null) {
                Processing::fail('Dated rates and caps are required before any paid wording request.');
            }
            $day = AiUsage::reserve($record->inquiry, $settings, $reservation);
            $run = AiRun::create(['inquiry_id' => $record->inquiry_id, 'requested_by' => $staff->id, 'budget_day_id' => $day->id, 'purpose' => 'rfq_wording', 'rfq_revision_id' => $revision->id, 'identity' => $identity, 'generation' => ($previous?->generation ?? 0) + 1, 'shipment_hash' => $record->inquiry->snapshotHash(), 'shipment_revision' => $record->inquiry->shipment_revision, 'model' => $settings->model, 'prompt_version' => config('rfq.prompt_version'), 'schema_version' => config('rfq.schema_version'), 'settings' => $settings->configuration + ['max_output_tokens' => config('ai.output_tokens'), 'prompt_hash' => hash('sha256', RfqWording::prompt()), 'schema_hash' => Processing::hash(RfqWording::schema())], 'sources' => $sources, 'working_snapshot' => ['rfq_revision_id' => $revision->id], 'input_characters' => mb_strlen(RfqWording::input($sources)), 'input_bound' => $bound, 'reservation' => $reservation, 'retry_reason' => $reason, 'state' => 'queued']);
            RequestAiProposals::dispatch($run->id)->onQueue('ai')->afterCommit();

            return $run;
        });
    }

    public function apply(Rfq $rfq, AiRun $run, User $staff, int $expected): void
    {
        DB::transaction(function () use ($rfq, $run, $staff, $expected): void {
            $record = app(ManageRfq::class)->locked($rfq, $staff, $expected);
            $run = AiRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->purpose !== 'rfq_wording' || $run->inquiry_id !== $record->inquiry_id || $run->rfq_revision_id !== $record->current()->id || $run->state !== 'needs_review' || $run->stale()) {
                Processing::fail('This wording suggestion is stale, approved or belongs to another request. No newer content was overwritten.');
            }
            RfqWording::validate($run->result);
            $p = $record->current()->payload;
            $data = $p + ['expected_revision' => $expected, 'to_contact_id' => $p['to']['id'] ?? null, 'cc_contact_ids' => array_column($p['cc'], 'id'), 'document_ids' => array_column($p['manifest'], 'document_id'), 'refresh_company' => false];
            $data['response_due_at'] = $p['response_due_at'] ? InquiryWorkflow::local(CarbonImmutable::parse($p['response_due_at'])) : null;
            foreach (['subject', 'opening', 'closing'] as $key) {
                $data[$key] = $run->result[$key];
            }
            $data['vendor_notes'] = $run->result['quotation_request'];
            $data['_origin'] = 'ai_reviewed';
            $data['change_reason'] = 'Human applied wording suggestion from AI run '.$run->id.'; deterministic facts/checklist and recipients/files retained.';
            app(ManageRfq::class)->save($record, $staff, $data);
            $run->update(['review_outcome' => 'applied']);
            Audit::record('RFQ wording suggestion reviewed and applied', $record, actor: $staff, vendorId: $record->vendor_id, details: ['wording_review' => ['before' => $expected, 'after' => ['run_id' => $run->id, 'revision' => $expected + 1]]]);
        });
    }
}
