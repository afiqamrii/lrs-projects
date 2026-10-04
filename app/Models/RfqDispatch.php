<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqDispatch extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['recipients' => 'array', 'sent_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(RfqApproval::class, 'rfq_approval_id');
    }
}
