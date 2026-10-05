<?php

namespace App\Console\Commands;

use App\Jobs\WorkerHeartbeat;
use App\Models\CompanySetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OperationsHeartbeat extends Command
{
    protected $signature = 'lrs:operations-heartbeat';

    protected $description = 'Record an observed scheduler tick and queue a bounded worker heartbeat';

    public function handle(): int
    {
        if (config('operations.restore_lockdown')) {
            $this->warn('Restore lockdown: no heartbeat job or external work queued.');

            return self::SUCCESS;
        }
        CompanySetting::whereKey(1)->update(['scheduler_seen_at' => now()]);
        if (! DB::table('jobs')->where('queue', 'health')->exists()) {
            WorkerHeartbeat::dispatch()->onQueue('health');
        }
        $this->info('Scheduler invocation observed. Worker heartbeat awaits an actual worker.');

        return self::SUCCESS;
    }
}
