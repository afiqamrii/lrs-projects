<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowupAuthorization extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
    }

    public function clientQuotationApproval(): BelongsTo
    {
        return $this->belongsTo(ClientQuotationApproval::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FollowupPlan::class, 'followup_plan_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(FollowupPolicy::class, 'followup_policy_id');
    }
}
