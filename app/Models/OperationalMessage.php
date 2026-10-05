<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OperationalMessage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['snapshot', 'content', 'pdf_path'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'number' => 'integer', 'resend_of_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class, 'inquiry_id');
    }

    public function reconfirmation(): BelongsTo
    {
        return $this->belongsTo(VendorReconfirmation::class, 'vendor_reconfirmation_id');
    }

    public function handoff(): BelongsTo
    {
        return $this->belongsTo(HandoffRevision::class, 'handoff_revision_id');
    }

    public function approval(): HasOne
    {
        return $this->hasOne(OperationalMessageApproval::class);
    }
}
