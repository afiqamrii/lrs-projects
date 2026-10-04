<?php

namespace App\Console\Commands;

use App\Models\DocumentRun;
use App\Support\LocalExtractor;
use Illuminate\Console\Command;

class ExtractRun extends Command
{
    protected $signature = 'lrs:extract-run {run}';

    protected $description = 'Process an already staff-authorized, claimed private extraction run';

    public function handle(LocalExtractor $extractor): int
    {
        $run = DocumentRun::findOrFail((int) $this->argument('run'));
        if ($run->state !== 'processing') {
            return self::FAILURE;
        }
        $extractor->handle($run);

        return self::SUCCESS;
    }
}
