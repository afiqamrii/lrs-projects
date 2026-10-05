<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FollowupPolicy extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'snapshot' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
    }

    public static function latestFor(string $kind): ?self
    {
        return self::where('kind', $kind)->latest('number')->first();
    }
}
