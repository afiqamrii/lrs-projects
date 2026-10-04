<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Clarification extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['approved_at' => 'immutable_datetime', 'communicated_at' => 'immutable_datetime', 'lock_version' => 'integer', 'shipment_revision' => 'integer'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function outlookDispatch(): ?MailDispatch
    {
        return MailDispatch::where('source_key', 'clarification:'.$this->id)->where('status', '!=', 'cancelled')->latest('id')->first();
    }

    public function currentFor(Inquiry $inquiry): bool
    {
        return $this->shipment_revision === $inquiry->shipment_revision && hash_equals($this->shipment_hash, $inquiry->snapshotHash()) && $inquiry->client?->is_active && ClientContact::whereKey($this->client_contact_id)->where('client_id', $inquiry->client_id)->where('is_active', true)->where('email', $this->recipient_email)->exists();
    }
}
