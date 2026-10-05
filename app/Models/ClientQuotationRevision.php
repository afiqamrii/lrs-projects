<?php

namespace App\Models;

use App\Support\QuotationEligibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class ClientQuotationRevision extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'pricing', 'source_snapshot', 'pdf_path'];

    protected function casts(): array
    {
        return ['resend_of_id' => 'integer', 'payload' => 'array', 'pricing' => 'array', 'source_snapshot' => 'array', 'created_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(ClientQuotation::class, 'client_quotation_id');
    }

    public function selection(): BelongsTo
    {
        return $this->belongsTo(OfferSelection::class, 'offer_selection_id');
    }

    public function approval(): HasOne
    {
        return $this->hasOne(ClientQuotationApproval::class);
    }

    public function label(): string
    {
        if ($this->quotation->current_number !== $this->number) {
            return 'Superseded';
        }
        if ($this->expires_at?->isPast()) {
            return 'Expired';
        }
        $approval = $this->approval;
        if ($approval && DB::table('quotation_manual_sends')->where('client_quotation_approval_id', $approval->id)->exists()) {
            return 'Manually recorded as sent';
        }
        $dispatch = $approval?->envelopes()->with('dispatches')->get()->flatMap->dispatches->sortByDesc('id')->first();
        if ($dispatch) {
            return $dispatch->label();
        }
        if ($approval) {
            return QuotationEligibility::reasons($this) ? 'Approved · release blocked' : 'Approved';
        }

        return $this->state === 'needs_review' ? 'Needs review' : 'Draft';
    }
}
