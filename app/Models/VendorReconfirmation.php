<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorReconfirmation extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['snapshot', 'content', 'pdf_path'];

    protected function casts(): array
    {
        return ['current_number' => 'integer'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class, 'inquiry_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(ClientDecision::class, 'client_decision_id');
    }

    public function selection(): BelongsTo
    {
        return $this->belongsTo(OfferSelection::class, 'offer_selection_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ShipmentVersion::class, 'shipment_version_id');
    }

    public function current(): ?VendorConfirmation
    {
        return $this->confirmations()->where('number', $this->current_number)->first();
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(VendorConfirmation::class);
    }
}
