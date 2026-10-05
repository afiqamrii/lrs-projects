<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorConfirmation extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['snapshot', 'content', 'pdf_path'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'snapshot' => 'encrypted:array', 'confirmed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class, 'inquiry_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(VendorReconfirmation::class, 'vendor_reconfirmation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
