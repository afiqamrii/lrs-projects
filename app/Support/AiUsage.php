<?php

namespace App\Support;

use App\Models\AiBudgetDay;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Inquiry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class AiUsage
{
    public static function scope(array $sources, AiSetting $settings, string $purpose = 'shipment_proposals'): string
    {
        if ($purpose === 'rfq_wording') {
            return Processing::hash(['purpose' => $purpose, 'sources' => $sources, 'model' => $settings->model, 'settings' => $settings->configuration, 'output' => config('ai.output_tokens'), 'prompt_hash' => hash('sha256', RfqWording::prompt()), 'schema_hash' => Processing::hash(RfqWording::schema())]);
        }

        return Processing::hash(['sources' => $sources, 'model' => $settings->model, 'settings' => $settings->configuration,
            'output' => config('ai.output_tokens'), 'prompt' => config('ai.prompt_version'), 'schema' => config('ai.schema_version'),
            'prompt_hash' => hash('sha256', ProposalSchema::prompt()), 'schema_hash' => Processing::hash(ProposalSchema::schema())]);
    }

    public static function bound(array $sources, string $purpose = 'shipment_proposals'): int
    {
        if ($purpose === 'rfq_wording') {
            return strlen(RfqWording::input($sources)) + strlen(RfqWording::prompt()) + strlen(json_encode(RfqWording::schema(), JSON_THROW_ON_ERROR)) + 4096;
        }

        return strlen(AiSources::input($sources)) + strlen(ProposalSchema::prompt()) + strlen(json_encode(ProposalSchema::schema(), JSON_THROW_ON_ERROR)) + 4096;
    }

    public static function cost(int $input, int $output, array $rates, int $cached = 0): string
    {
        $cached = max(0, min($input, $cached));
        $inputRate = BigDecimal::of($rates['input_rate']);
        $cachedRate = BigDecimal::of($rates['cached_rate'] ?? $rates['input_rate']);
        $cost = $inputRate->multipliedBy($input - $cached)->plus($cachedRate->multipliedBy($cached))->plus(BigDecimal::of($rates['output_rate'])->multipliedBy($output));

        return (string) $cost->dividedBy('1000000', 8, RoundingMode::Ceiling);
    }

    public static function estimate(array $sources, AiSetting $settings, string $purpose = 'shipment_proposals'): ?string
    {
        $rates = $settings->configuration;
        foreach (['input_rate', 'output_rate', 'rate_version', 'rate_date', 'run_cap', 'inquiry_cap', 'daily_cap', 'structured_verified'] as $key) {
            if (! isset($rates[$key]) || $rates[$key] === '' || $rates[$key] === false) {
                return null;
            }
        }

        if (isset($rates['cached_rate']) && BigDecimal::of($rates['cached_rate'])->isGreaterThan(BigDecimal::of($rates['input_rate']))) {
            $rates['input_rate'] = $rates['cached_rate'];
        }

        return self::cost(self::bound($sources, $purpose), (int) config('ai.output_tokens'), $rates);
    }

    public static function unavailable(AiSetting $settings): array
    {
        $reasons = [];
        if (! $settings->enabled) {
            $reasons[] = 'Live AI is disabled by Admin.';
        }
        if (! config('ai.key')) {
            $reasons[] = 'Server API credentials are not configured.';
        }
        if (! $settings->model) {
            $reasons[] = 'A runtime model has not been explicitly selected.';
        }
        if (! ($settings->configuration['structured_verified'] ?? false)) {
            $reasons[] = 'Admin must verify current structured-output support for the chosen model.';
        }
        if (($settings->model_check['model'] ?? null) !== $settings->model || ($settings->model_check['state'] ?? null) !== 'accessible') {
            $reasons[] = 'Configured account model access has not been checked.';
        }
        foreach (['input_rate', 'output_rate', 'rate_version', 'rate_date', 'run_cap', 'inquiry_cap', 'daily_cap'] as $key) {
            if (! isset($settings->configuration[$key]) || $settings->configuration[$key] === '') {
                $reasons[] = 'Current dated rates and all monetary caps are required.';
                break;
            }
        }

        return array_values(array_unique($reasons));
    }

    public static function reserve(Inquiry $inquiry, AiSetting $settings, string $reservation): AiBudgetDay
    {
        $rates = $settings->configuration;
        if (BigDecimal::of($reservation)->isGreaterThan(BigDecimal::of($rates['run_cap']))) {
            Processing::fail('This request exceeds the per-run monetary cap. Select less text or ask Admin to review the configured limit.');
        }
        $caseRuns = AiRun::where('inquiry_id', $inquiry->id)->where('is_demo', false)->get();
        $caseSpend = BigDecimal::of(0);
        foreach ($caseRuns as $caseRun) {
            $caseSpend = $caseSpend->plus($caseRun->reservation)->plus($caseRun->estimated_cost ?? '0');
            if ($caseRun->cost_uncertain) {
                Processing::fail('This inquiry has uncertain provider usage. Admin must reconcile it before another paid request.');
            }
        }
        if ($caseRuns->sum('attempts') + $caseRuns->where('state', 'queued')->count() >= config('ai.paid_attempts_per_inquiry')) {
            Processing::fail('This inquiry has reached its bounded paid-attempt limit. Continue with manual entry.');
        }
        if ($caseSpend->plus($reservation)->isGreaterThan(BigDecimal::of($rates['inquiry_cap']))) {
            Processing::fail('This inquiry would exceed its monetary cap, including reserved requests.');
        }
        $dayKey = now('UTC')->toDateString();
        AiBudgetDay::firstOrCreate(['day' => $dayKey]);
        $day = AiBudgetDay::where('day', $dayKey)->lockForUpdate()->firstOrFail();
        if (BigDecimal::of($day->spent)->plus($day->reserved)->plus($reservation)->isGreaterThan(BigDecimal::of($rates['daily_cap']))) {
            Processing::fail('The daily UTC monetary cap would be exceeded, including in-flight and uncertain reservations.');
        }

        $day->reserved = (string) BigDecimal::of($day->reserved)->plus($reservation);
        $day->save();

        return $day;
    }

    public static function settle(AiRun $run, ?array $usage, array $metadata, bool $uncertain): void
    {
        DB::transaction(function () use ($run, $usage, $metadata, $uncertain): void {
            AiSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $record = AiRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($record->completed_at) {
                return;
            }
            $valid = $usage && isset($usage['input_tokens'], $usage['output_tokens']) && is_int($usage['input_tokens']) && is_int($usage['output_tokens']) && $usage['input_tokens'] >= 0 && $usage['output_tokens'] >= 0;
            $cost = $valid ? self::cost($usage['input_tokens'], $usage['output_tokens'], $record->settings, (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0)) : null;
            $record->fill($metadata);
            $record->usage = $usage;
            $record->estimated_cost = $cost;
            $record->cost_uncertain = $uncertain || ! $valid;
            if ($record->budget_day_id && $valid && ! $uncertain) {
                $day = AiBudgetDay::whereKey($record->budget_day_id)->lockForUpdate()->firstOrFail();
                $day->reserved = (string) BigDecimal::of($day->reserved)->minus($record->reservation);
                $day->spent = (string) BigDecimal::of($day->spent)->plus($cost);
                $day->save();
                $record->reservation = '0';
            }
            $record->completed_at = now();
            $record->save();
        });
    }

    public static function reconcile(AiRun $run, string $cost, string $reason): void
    {
        DB::transaction(function () use ($run, $cost, $reason): void {
            AiSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $record = AiRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! $record->cost_uncertain || ! $record->completed_at || ! $record->budget_day_id) {
                Processing::fail('Only completed uncertain provider outcomes can be reconciled.');
            }
            $day = AiBudgetDay::whereKey($record->budget_day_id)->lockForUpdate()->firstOrFail();
            $day->reserved = (string) BigDecimal::of($day->reserved)->minus($record->reservation);
            $day->spent = (string) BigDecimal::of($day->spent)->plus($cost);
            $day->save();
            $record->update(['estimated_cost' => $cost, 'reservation' => '0', 'cost_uncertain' => false]);
            Audit::record('AI usage reconciled from provider evidence', $record, details: ['reconciliation' => ['before' => 'Uncertain', 'after' => ['cost_usd' => $cost, 'reason' => $reason]]]);
        });
    }
}
