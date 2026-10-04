<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourcingRound extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ShipmentVersion::class, 'shipment_version_id');
    }

    public function rfqs(): HasMany
    {
        return $this->hasMany(Rfq::class);
    }
}
