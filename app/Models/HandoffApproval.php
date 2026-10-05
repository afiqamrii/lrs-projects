<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandoffApproval extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['snapshot', 'content', 'pdf_path'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'approved_at' => 'immutable_datetime'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(HandoffRevision::class, 'handoff_revision_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
