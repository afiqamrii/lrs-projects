<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HandoffRevision extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['snapshot', 'content', 'pdf_path'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'number' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function handoff(): BelongsTo
    {
        return $this->belongsTo(BookingHandoff::class, 'booking_handoff_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(ClientDecision::class, 'client_decision_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(ClientQuotationRevision::class, 'client_quotation_revision_id');
    }

    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(VendorConfirmation::class, 'vendor_confirmation_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(HandoffPolicy::class, 'handoff_policy_id');
    }

    public function approval(): HasOne
    {
        return $this->hasOne(HandoffApproval::class);
    }
}
