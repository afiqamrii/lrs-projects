<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientQuotation extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ClientQuotationRevision::class)->orderByDesc('number');
    }

    public function current(): ?ClientQuotationRevision
    {
        return $this->revisions()->where('number', $this->current_number)->first();
    }
}
