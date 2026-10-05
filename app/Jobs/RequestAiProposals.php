<?php

namespace App\Jobs;

use App\Models\AiBudgetDay;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Support\AiUsage;
use App\Support\OfferProposal;
use App\Support\OpenAiResponses;
use App\Support\Processing;
use App\Support\ProposalEvidence;
use App\Support\ProposalSchema;
use App\Support\RfqWording;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class RequestAiProposals implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 100;

    public function __construct(public int $runId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('ai:'.$this->runId))->releaseAfter(10)->expireAfter(120)];
    }

    public function handle(OpenAiResponses $provider): void
    {
        $run = DB::transaction(function (): ?AiRun {
            $settings = AiSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $run = AiRun::whereKey($this->runId)->lockForUpdate()->firstOrFail();
            if ($run->state !== 'queued') {
                return null;
            }
            $wording = $run->purpose === 'rfq_wording';
            $quotation = $run->purpose === 'vendor_quotation';
            if (AiUsage::unavailable($settings) || $settings->model !== $run->model || $run->stale()
                || $run->prompt_version !== config($quotation ? 'offers.prompt_version' : ($wording ? 'rfq.prompt_version' : 'ai.prompt_version')) || $run->schema_version !== config($quotation ? 'offers.schema_version' : ($wording ? 'rfq.schema_version' : 'ai.schema_version'))
                || ($run->settings['prompt_hash'] ?? null) !== hash('sha256', ($quotation ? OfferProposal::prompt() : ($wording ? RfqWording::prompt() : ProposalSchema::prompt())))
                || ($run->settings['schema_hash'] ?? null) !== Processing::hash(($quotation ? OfferProposal::schema() : ($wording ? RfqWording::schema() : ProposalSchema::schema())))
                || Processing::hash(array_diff_key($run->settings, array_flip(['max_output_tokens', 'prompt_hash', 'schema_hash']))) !== Processing::hash($settings->configuration)) {
                $day = AiBudgetDay::whereKey($run->budget_day_id)->lockForUpdate()->firstOrFail();
                $day->reserved = (string) BigDecimal::of($day->reserved)->minus($run->reservation);
                $day->save();
                $run->update(['state' => 'unavailable', 'reservation' => '0', 'error_code' => 'configuration_or_source_changed', 'error_message' => 'Live configuration or source/working shipment changed before dispatch. No provider call was made. Review a fresh scope.', 'completed_at' => now()]);

                return null;
            }
            $run->update(['state' => 'processing', 'attempts' => $run->attempts + 1, 'started_at' => now()]);

            return $run;
        });
        if (! $run) {
            return;
        }
        try {
            $response = $provider->request($run);
            $result = $response['result'];
            $error = $response['error'];
            $metadata = $response['metadata'] + [
                'state' => $error ? 'failed' : 'needs_review',
                'error_code' => $error, 'error_message' => $error ? match ($error) {
                    'refused' => 'The provider refused this request. Use manual entry or review a smaller permitted source selection.',
                    'incomplete' => 'The provider did not complete the structured output. No proposals can be applied. Another paid attempt requires explicit review.',
                    'invalid_output' => 'The provider output failed the permitted schema. No fields have been applied.',
                    'rate_limited' => 'The provider rate-limited this request. Check usage and explicitly review before another paid attempt.',
                    default => 'The provider reported a failure. Usage may still require reconciliation. No shipment fields have been applied.',
                } : null,
            ];
            if ($result) {
                $metadata['result'] = $result;
                $metadata['proposals'] = $run->purpose === 'vendor_quotation' ? OfferProposal::inspect($run, $result) : ($run->purpose === 'rfq_wording' ? null : ProposalEvidence::inspect($run, $result));
            }
            AiUsage::settle($run, is_array($response['usage']) ? $response['usage'] : null, $metadata, false);
        } catch (\Throwable $exception) {
            $this->failed(null);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $run = AiRun::find($this->runId);
        if ($run && $run->state === 'processing' && ! $run->completed_at) {
            AiUsage::settle($run, null, ['state' => 'failed', 'error_code' => 'uncertain_provider_outcome', 'error_message' => 'The request may have reached the provider before processing stopped. Usage/cost are uncertain and the reservation is held. Admin must reconcile provider evidence before another paid request.'], true);
        }
    }
}
