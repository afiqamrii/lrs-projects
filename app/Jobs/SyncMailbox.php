<?php

namespace App\Jobs;

use App\Actions\MailboxSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMailbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $folderId) {}

    public function handle(MailboxSync $sync): void
    {
        $sync->handle($this->folderId);
    }
}
