<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RfqRevision extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'number' => 'integer'];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function approval(): HasOne
    {
        return $this->hasOne(RfqApproval::class);
    }

    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    public const STATES = ['draft' => 'Draft', 'needs_approval' => 'Needs approval', 'changes_requested' => 'Changes requested', 'approved' => 'Approved', 'superseded' => 'Superseded', 'cancelled' => 'Cancelled'];
}
