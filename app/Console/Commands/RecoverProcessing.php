<?php

namespace App\Console\Commands;

use App\Jobs\ExtractDocument;
use App\Jobs\RequestAiProposals;
use App\Models\AiBudgetDay;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\DocumentRun;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverProcessing extends Command
{
    protected $signature = 'lrs:recover-processing {--age=10 : Minimum inactive minutes; at least 10} {--dry-run}';

    protected $description = 'Close abandoned private runs safely; paid provider outcomes stay uncertain until reconciliation';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(max(10, (int) $this->option('age')));
        $documents = DocumentRun::whereIn('state', ['queued', 'processing'])->where('updated_at', '<', $cutoff)->get()->filter(fn (DocumentRun $run): bool => $run->updated_at->lt(now()->subSeconds(max(600, (int) $run->configuration['limits']['run_timeout'] + 60))));
        $runs = AiRun::whereIn('state', ['queued', 'processing'])->where('updated_at', '<', $cutoff)->get();
        $this->info($documents->count().' local and '.$runs->count().' AI runs meet the recovery age.');
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        foreach ($documents as $document) {
            DB::transaction(function () use ($document, $cutoff): void {
                $current = DocumentRun::whereKey($document->id)->lockForUpdate()->firstOrFail();
                if ($current->updated_at->lt($cutoff) && $current->updated_at->lt(now()->subSeconds(max(600, (int) $current->configuration['limits']['run_timeout'] + 60)))) {
                    (new ExtractDocument($current->id))->failed(null);
                }
            });
        }
        foreach ($runs as $run) {
            if ($run->state === 'processing') {
                (new RequestAiProposals($run->id))->failed(null);
            } else {
                DB::transaction(function () use ($run, $cutoff): void {
                    AiSetting::whereKey(1)->lockForUpdate()->firstOrFail();
                    $record = AiRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                    if ($record->state !== 'queued' || $record->updated_at->gte($cutoff)) {
                        return;
                    }
                    $day = AiBudgetDay::whereKey($record->budget_day_id)->lockForUpdate()->firstOrFail();
                    $day->reserved = (string) BigDecimal::of($day->reserved)->minus($record->reservation);
                    $day->save();
                    $record->update(['state' => 'unavailable', 'reservation' => '0', 'completed_at' => now(), 'error_code' => 'queue_abandoned', 'error_message' => 'The queue did not dispatch this request before recovery. No provider attempt was made. Review a fresh source scope.']);
                });
            }
        }
        $this->info('Recovered records retain evidence. Local retry is explicit; uncertain paid reservations remain held.');

        return self::SUCCESS;
    }
}
