<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rfq extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['current_number' => 'integer'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(SourcingRound::class, 'sourcing_round_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(RfqRevision::class);
    }

    public function current(): RfqRevision
    {
        return $this->revisions()->where('number', $this->current_number)->firstOrFail();
    }
}
