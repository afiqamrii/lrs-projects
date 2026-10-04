<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailDispatch extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['upload_state', 'lease'];

    public const STATES = ['queued' => 'Queued', 'preparing' => 'Preparing', 'ready' => 'Ready for submission', 'submitting' => 'Submitting · outcome pending', 'accepted' => 'Provider accepted · delivery unconfirmed', 'observed' => 'Sent Item observed · delivery unconfirmed', 'failed' => 'Failed before confirmed acceptance', 'uncertain' => 'Outcome uncertain · do not resend', 'cancelled' => 'Cancelled before submission'];

    protected function casts(): array
    {
        return ['upload_state' => 'encrypted:array', 'is_demo' => 'boolean', 'attempts' => 'integer', 'reconcile_attempts' => 'integer', 'requested_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'draft_started_at' => 'immutable_datetime', 'submission_started_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime', 'cancel_requested_at' => 'immutable_datetime'];
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(MailEnvelope::class, 'mail_envelope_id');
    }

    public function label(): string
    {
        return self::STATES[$this->status] ?? $this->status;
    }
}
