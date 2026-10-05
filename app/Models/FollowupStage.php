<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FollowupStage extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'due_at' => 'immutable_datetime', 'counted_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FollowupPlan::class, 'followup_plan_id');
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(FollowupAuthorization::class, 'followup_authorization_id');
    }

    public function envelope(): HasOne
    {
        return $this->hasOne(MailEnvelope::class);
    }
}
