<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorOffer extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source' => 'array', 'current_number' => 'integer'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(RfqRevision::class, 'rfq_revision_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ShipmentVersion::class, 'shipment_version_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(VendorOfferRevision::class)->orderByDesc('number');
    }

    public function supersededBy(): ?self
    {
        return self::where('inquiry_id', $this->inquiry_id)->where('source->supersedes_offer_id', $this->id)->latest('id')->first();
    }

    public function current(): ?VendorOfferRevision
    {
        return $this->revisions()->where('number', $this->current_number)->first();
    }
}
