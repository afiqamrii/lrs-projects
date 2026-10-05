<?php

namespace App\Jobs;

use App\Models\CompanySetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WorkerHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function handle(): void
    {
        if (! config('operations.restore_lockdown')) {
            CompanySetting::whereKey(1)->update(['worker_seen_at' => now()]);
        }
    }
}
