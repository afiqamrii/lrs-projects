<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferSelection extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'selected_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(VendorOfferRevision::class, 'vendor_offer_revision_id');
    }

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(OfferComparison::class, 'offer_comparison_id');
    }
}
