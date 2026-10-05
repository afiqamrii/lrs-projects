<?php

namespace App\Actions;

use App\Jobs\RequestAiProposals;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\User;
use App\Models\VendorOffer;
use App\Support\AiUsage;
use App\Support\OfferProposal;
use App\Support\Processing;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StartOfferProposals
{
    public function handle(VendorOffer $offer, User $staff, int $expected, string $scope, array $sourceIds, bool $retry = false, ?string $reason = null): AiRun
    {
        return DB::transaction(function () use ($offer, $staff, $expected, $scope, $sourceIds, $retry, $reason): AiRun {
            $settings = AiSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $record = app(ManageOffer::class)->locked($offer, $staff, $expected);
            $revision = $record->current();
            if (! $revision) {
                Processing::fail('Save a manual draft before requesting commercial proposals.');
            }
            $sources = array_values(array_filter(OfferProposal::sources($revision), fn (array $s): bool => in_array($s['id'], $sourceIds, true)));
            if (! $sources || count($sources) > 200 || ! OfferProposal::sourcesCurrent($revision) || mb_strlen(OfferProposal::input($sources)) > config('ai.input_chars')) {
                Processing::fail('Choose current, bounded vendor source passages. No raw file or customer invoice is sent.');
            }
            if (! hash_equals($scope, AiUsage::scope($sources, $settings, 'vendor_quotation'))) {
                Processing::fail('Source scope or model/rates changed. Preview the current scope.');
            }
            $identity = Processing::hash(['purpose' => 'vendor_quotation', 'revision_id' => $revision->id, 'scope' => $scope]);
            $previous = AiRun::where('inquiry_id', $record->inquiry_id)->where('identity', $identity)->latest('generation')->first();
            if ($previous && in_array($previous->state, ['queued', 'processing', 'needs_review'], true)) {
                return $previous;
            }
            if ($previous && (! $retry || ! trim($reason ?? '') || $previous->cost_uncertain)) {
                Processing::fail('Explicit retry reason and resolved usage are required.');
            }
            if ($reasons = AiUsage::unavailable($settings)) {
                Processing::fail(implode(' ', $reasons));
            }
            $bound = AiUsage::bound($sources, 'vendor_quotation');
            if ($bound > config('ai.input_tokens')) {
                Processing::fail('Quotation scope exceeds the configured input bound.');
            }
            $reservation = AiUsage::estimate($sources, $settings, 'vendor_quotation');
            if ($reservation === null) {
                Processing::fail('Dated rates and caps are required.');
            }
            $day = AiUsage::reserve($record->inquiry, $settings, $reservation);
            $run = AiRun::create(['inquiry_id' => $record->inquiry_id, 'requested_by' => $staff->id, 'budget_day_id' => $day->id, 'purpose' => 'vendor_quotation', 'vendor_offer_revision_id' => $revision->id, 'identity' => $identity, 'generation' => ($previous?->generation ?? 0) + 1, 'shipment_hash' => $record->inquiry->snapshotHash(), 'shipment_revision' => $record->inquiry->shipment_revision, 'model' => $settings->model, 'prompt_version' => config('offers.prompt_version'), 'schema_version' => config('offers.schema_version'), 'settings' => $settings->configuration + ['max_output_tokens' => config('ai.output_tokens'), 'prompt_hash' => hash('sha256', OfferProposal::prompt()), 'schema_hash' => Processing::hash(OfferProposal::schema())], 'sources' => $sources, 'working_snapshot' => ['offer_revision_id' => $revision->id], 'input_characters' => mb_strlen(OfferProposal::input($sources)), 'input_bound' => $bound, 'reservation' => $reservation, 'retry_reason' => $reason, 'state' => 'queued']);
            RequestAiProposals::dispatch($run->id)->onQueue('ai')->afterCommit();

            return $run;
        });
    }

    public function apply(VendorOffer $offer, AiRun $run, User $staff, array $data): void
    {
        $data = Validator::make($data, ['expected_revision' => 'required|integer', 'decisions' => 'required|array|max:200', 'decisions.*.decision' => 'required|in:accept,correct,reject,unresolved', 'decisions.*.value' => 'nullable|string|max:2000', 'decisions.*.reason' => 'nullable|string|max:2000'])->validate();
        DB::transaction(function () use ($offer, $run, $staff, $data): void {
            $record = app(ManageOffer::class)->locked($offer, $staff, (int) $data['expected_revision']);
            $run = AiRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->purpose !== 'vendor_quotation' || $run->vendor_offer_revision_id !== $record->current()?->id || $run->state !== 'needs_review' || $run->stale() || $run->review_outcome === 'applied') {
                Processing::fail('These proposals are stale, already applied or belong to another offer.');
            }
            $proposals = OfferProposal::inspect($run, $run->result);
            $p = $record->current()->payload;
            $review = [];
            foreach ($proposals as $i => $proposal) {
                $decision = $data['decisions'][$i] ?? null;
                if (! $decision) {
                    Processing::fail('Record a decision for each commercial proposal; unresolved is allowed.');
                }
                $choice = $decision['decision'];
                if ($choice === 'accept' && (! $proposal['supported'] || $proposal['uncertainty'] !== '')) {
                    Processing::fail('Unsupported or uncertain values require correction with human source evidence or remain unresolved.');
                }
                if ($choice === 'correct' && (! trim($decision['reason'] ?? '') || ($decision['value'] ?? null) === null)) {
                    Processing::fail('Corrected values require a source / reason and explicit value.');
                }
                if (in_array($choice, ['accept', 'correct'], true)) {
                    $value = $choice === 'accept' ? $proposal['value'] : $decision['value'];
                    $field = $proposal['field'];
                    if (str_starts_with($field, 'lines.')) {
                        $index = (int) explode('.', $field)[1];
                        for ($n = count($p['lines']); $n <= $index; $n++) {
                            $p['lines'][] = ManageOffer::blankLine($n + 1);
                        }
                    }
                    Arr::set($p, $field, $value);
                }
                $review[] = ['proposal' => $proposal, 'decision' => $decision, 'reviewer' => $staff->id];
            }
            $p['expected_revision'] = $record->current_number;
            $p['change_reason'] = 'Explicit human review of quotation proposal run '.$run->id;
            app(ManageOffer::class)->save($record, $staff, $p, false, ['run_id' => $run->id, 'decisions' => $review]);
            $run->update(['review_outcome' => 'applied']);
        });
    }
}
