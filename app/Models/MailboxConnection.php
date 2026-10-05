<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailboxConnection extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['provider' => 'outlook', 'incoming_enabled' => true];

    protected $hidden = ['access_token', 'refresh_token', 'refresh_lease'];

    protected function casts(): array
    {
        return ['aliases' => 'encrypted:array', 'granted_scopes' => 'encrypted:array', 'incoming_enabled' => 'boolean', 'aliases_checked_at' => 'immutable_datetime', 'access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'is_demo' => 'boolean', 'rights_confirmed' => 'boolean', 'account_sent_items' => 'boolean', 'generation' => 'integer', 'transport_limit' => 'integer', 'expires_at' => 'immutable_datetime', 'refresh_until' => 'immutable_datetime', 'import_from' => 'immutable_datetime', 'last_sync_at' => 'immutable_datetime'];
    }

    public static function current(): self
    {
        $id = CompanySetting::current()->outbound_mailbox_id ?? 1;

        return self::unguarded(fn (): self => self::firstOrCreate(['id' => $id]));
    }

    public function folders(): HasMany
    {
        return $this->hasMany(MailboxFolder::class);
    }

    public function usable(): bool
    {
        return ! config('operations.restore_lockdown') && $this->state === 'connected' && $this->rights_confirmed && (! $this->is_demo || (config('mailbox.demo_enabled') && app()->environment('local', 'testing')));
    }

    public function verifiedGmailFrom(): bool
    {
        $expected = mb_strtolower($this->from_alias ?: $this->account_email);

        return collect($this->aliases ?? [])->contains(fn ($alias): bool => mb_strtolower($alias['sendAsEmail'] ?? '') === $expected && (($alias['verificationStatus'] ?? '') === 'accepted' || (! empty($alias['isPrimary']) && $expected === mb_strtolower($this->account_email))));
    }

    public function envelope(string $reply, string $replyName): array
    {
        if ($this->provider === 'gmail') {
            return ['provider' => 'gmail', 'connection_id' => $this->id, 'from' => ['email' => $this->from_alias ?: $this->account_email, 'name' => $this->target_name], 'sender' => ['email' => $this->account_email], 'reply_to' => ['email' => $reply, 'name' => $replyName], 'send_mode' => 'send_as', 'target_id' => $this->target_id, 'account_id' => $this->account_id, 'tenant_id' => 'google', 'is_demo' => $this->is_demo];
        }
        $routing = $this->id === 1 ? [] : ['provider' => 'outlook', 'connection_id' => $this->id];

        return $routing + ['from' => ['email' => $this->target_email, 'name' => $this->target_name], 'sender' => ['email' => $this->send_mode === 'on_behalf' ? $this->account_email : $this->target_email], 'reply_to' => ['email' => $reply, 'name' => $replyName], 'send_mode' => $this->send_mode, 'target_id' => $this->target_id, 'account_id' => $this->account_id, 'tenant_id' => $this->tenant_id, 'is_demo' => $this->is_demo];
    }
}
