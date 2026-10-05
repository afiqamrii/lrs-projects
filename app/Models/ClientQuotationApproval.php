<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientQuotationApproval extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'approved_at' => 'immutable_datetime'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ClientQuotationRevision::class, 'client_quotation_revision_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(MailEnvelope::class);
    }
}
