<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiBudgetDay extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day' => 'immutable_date', 'spent' => 'decimal:8', 'reserved' => 'decimal:8'];
    }
}
