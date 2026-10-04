<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxVerification extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'template_body'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'invalidated_at' => 'immutable_datetime', 'claimed_at' => 'immutable_datetime', 'transport_submitted_at' => 'immutable_datetime'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function label(): string
    {
        return match ($this->transport_state) {
            'queued' => 'Confirmation email queued',
            'dispatching' => 'Send started · outcome not confirmed; reconcile before resending',
            'transport_submitted' => 'Submitted to mail transport · delivery not confirmed',
            'failed' => 'Transport reported a failure · delivery not confirmed',
            'unavailable' => 'Email unavailable · not externally sent',
            default => 'Email disabled · not externally sent',
        };
    }
}
