<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandoffPolicy extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['snapshot', 'content', 'pdf_path'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'number' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public static function current(): ?self
    {
        return self::latest('number')->first();
    }
}
