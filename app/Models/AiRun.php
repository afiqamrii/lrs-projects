<?php

namespace App\Models;

use App\Support\AiSources;
use App\Support\Processing;
use App\Support\RfqWording;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRun extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'sources' => 'array', 'working_snapshot' => 'array', 'usage' => 'array', 'result' => 'array', 'proposals' => 'array', 'is_demo' => 'boolean', 'cost_uncertain' => 'boolean', 'reservation' => 'decimal:8', 'estimated_cost' => 'decimal:8', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function rfqRevision(): BelongsTo
    {
        return $this->belongsTo(RfqRevision::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProposalReview::class)->latest('id');
    }

    public function stale(): bool
    {
        if ($this->purpose === 'rfq_wording') {
            return RfqWording::stale($this);
        }

        return ! hash_equals($this->shipment_hash, $this->inquiry->snapshotHash()) || ! AiSources::current($this->inquiry, $this->sources);
    }

    public function label(): string
    {
        return Processing::AI[$this->state];
    }
}
