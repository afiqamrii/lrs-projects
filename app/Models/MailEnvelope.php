<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailEnvelope extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'authorized_at' => 'immutable_datetime'];
    }

    public function operationalMessageApproval(): BelongsTo
    {
        return $this->belongsTo(OperationalMessageApproval::class);
    }

    public function followupStage(): BelongsTo
    {
        return $this->belongsTo(FollowupStage::class);
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(RfqApproval::class, 'rfq_approval_id');
    }

    public function clarification(): BelongsTo
    {
        return $this->belongsTo(Clarification::class);
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(MailDispatch::class);
    }

    public function clientQuotationApproval(): BelongsTo
    {
        return $this->belongsTo(ClientQuotationApproval::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(MailboxConnection::class, 'mailbox_connection_id');
    }
}
