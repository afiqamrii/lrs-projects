<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxFolder extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['cursor', 'page', 'lease'];

    protected function casts(): array
    {
        return ['cursor' => 'encrypted', 'page' => 'encrypted:array', 'enabled' => 'boolean', 'offset' => 'integer', 'failure_count' => 'integer', 'cycle' => 'integer', 'cycle_count' => 'integer', 'resync_count' => 'integer', 'import_from' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'last_sync_at' => 'immutable_datetime'];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(MailboxConnection::class, 'mailbox_connection_id');
    }

    public function mailboxKey(): string
    {
        return $this->mailbox->tenant_id.':'.$this->mailbox_id.($this->mailbox->is_demo ? ':fixture' : '');
    }
}
