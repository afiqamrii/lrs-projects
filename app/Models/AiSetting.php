<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'configuration' => 'array', 'model_check' => 'array'];
    }

    public static function current(): self
    {
        return static::findOrFail(1);
    }
}
