<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailboxConnection extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token', 'refresh_lease'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'is_demo' => 'boolean', 'rights_confirmed' => 'boolean', 'account_sent_items' => 'boolean', 'generation' => 'integer', 'transport_limit' => 'integer', 'expires_at' => 'immutable_datetime', 'refresh_until' => 'immutable_datetime', 'import_from' => 'immutable_datetime', 'last_sync_at' => 'immutable_datetime'];
    }

    public static function current(): self
    {
        return self::unguarded(fn (): self => self::firstOrCreate(['id' => 1]));
    }

    public function folders(): HasMany
    {
        return $this->hasMany(MailboxFolder::class);
    }

    public function usable(): bool
    {
        return $this->state === 'connected' && $this->rights_confirmed && (! $this->is_demo || (config('mailbox.demo_enabled') && app()->environment('local', 'testing')));
    }

    public function envelope(string $reply, string $replyName): array
    {
        return ['from' => ['email' => $this->target_email, 'name' => $this->target_name], 'sender' => ['email' => $this->send_mode === 'on_behalf' ? $this->account_email : $this->target_email], 'reply_to' => ['email' => $reply, 'name' => $replyName], 'send_mode' => $this->send_mode, 'target_id' => $this->target_id, 'account_id' => $this->account_id, 'tenant_id' => $this->tenant_id, 'is_demo' => $this->is_demo];
    }
}
