<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryDocument extends Model
{
    use HasFactory;

    public const CLASSES = ['packing_list' => 'Packing list', 'invoice' => 'Commercial / pro forma invoice', 'client_rfq' => 'Client RFQ / specification', 'freight_quote' => 'Existing freight quotation', 'correspondence' => 'Correspondence', 'other' => 'Other'];

    protected $guarded = ['id'];

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'size' => 'integer'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function previewable(): bool
    {
        return in_array($this->mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
    }
}
