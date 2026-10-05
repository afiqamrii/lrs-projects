<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorOfferRevision extends Model
{
    use HasFactory;

    public const STATES = ['draft' => 'Draft', 'needs_review' => 'Needs review', 'reviewed_gaps' => 'Reviewed with gaps', 'reviewed_complete' => 'Reviewed complete', 'superseded' => 'Superseded'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'calculation' => 'array', 'gaps' => 'array', 'reviewed_at' => 'immutable_datetime', 'known_total' => 'decimal:8', 'complete_total' => 'decimal:8', 'quoted_total' => 'decimal:8'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(VendorOffer::class, 'vendor_offer_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(VendorOfferCharge::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
