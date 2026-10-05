<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FollowupPlan extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['outbound_epoch' => 'integer', 'next_due_at' => 'immutable_datetime', 'fixture_at' => 'immutable_datetime', 'send_count' => 'integer'];
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(FollowupAuthorization::class, 'authorization_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(FollowupStage::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AttentionTask::class);
    }

    public function clock(): CarbonImmutable
    {
        return $this->fixture_at && $this->inquiry->is_demo && config('mailbox.demo_enabled') && app()->environment('local', 'testing') ? $this->fixture_at : now()->toImmutable();
    }
}
