<?php

namespace App\Jobs;

use App\Models\DocumentRun;
use App\Support\Processing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ExtractDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 330;

    public function __construct(public int $runId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('extract:'.$this->runId))->releaseAfter(10)->expireAfter(360)];
    }

    public function handle(): void
    {
        if (! DocumentRun::whereKey($this->runId)->where('state', 'queued')->update(['state' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'started_at' => now()])) {
            return;
        }
        $run = DocumentRun::findOrFail($this->runId);
        try {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit='.(int) $run->configuration['limits']['memory_mb'].'M', base_path('artisan'), 'lrs:extract-run', (string) $run->id, '--no-interaction'], base_path());
            $process->setTimeout((int) $run->configuration['limits']['run_timeout'] + 5);
            $process->disableOutput();
            $process->run();
            if (! $process->isSuccessful()) {
                $this->failed(null);
            }
        } catch (\Throwable $exception) {
            $this->failed(null);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $stopped = DB::transaction(function (): ?DocumentRun {
            $run = DocumentRun::whereKey($this->runId)->lockForUpdate()->first();
            $next = $run?->blocks ? 'partial' : 'failed';
            if ($run && Processing::canTransition('document', $run->state, $next)) {
                $run->update(['state' => $next, 'error_code' => 'worker_stopped', 'error_message' => 'Processing stopped before completion. Successful pages are retained. Reprocess explicitly with fewer pages, or use manual entry.', 'completed_at' => now()]);

                return $run;
            }

            return null;
        });
        if ($stopped) {
            $disk = Storage::disk('extraction');
            $base = 'inquiries/'.$stopped->inquiry_id.'/runs/'.$stopped->id;
            $root = realpath($disk->path(''));
            $runRoot = realpath($disk->path($base));
            $working = realpath($disk->path($base.'/work'));
            if ($root && $runRoot && $working && str_starts_with($runRoot, $root.DIRECTORY_SEPARATOR) && str_starts_with($working, $runRoot.DIRECTORY_SEPARATOR)) {
                $disk->deleteDirectory($base.'/work');
            }
        }
    }
}
