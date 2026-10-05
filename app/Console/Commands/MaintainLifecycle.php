<?php

namespace App\Console\Commands;

use App\Actions\ManageLifecycle;
use App\Models\MailMessage;
use Illuminate\Console\Command;

class MaintainLifecycle extends Command
{
    protected $signature = 'lifecycle:maintain';

    protected $description = 'Recover deduplicated human review tasks from matched incoming evidence; never accept or book.';

    public function handle(ManageLifecycle $a): int
    {
        MailMessage::where('direction', 'incoming')->where('match_state', 'matched')->where(fn ($q) => $q->whereNotNull('client_quotation_revision_id')->orWhereNotNull('operational_message_id'))->chunkById(100, function ($messages) use ($a) {
            foreach ($messages as $message) {
                $a->incoming($message, recover: true);
            }
        });
        $this->info('Matched response review tasks checked. No decisions, confirmations or bookings were inferred.');

        return self::SUCCESS;
    }
}
