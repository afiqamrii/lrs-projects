<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorOfferCharge extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'rate' => 'decimal:8', 'quantity' => 'decimal:8', 'minimum_charge' => 'decimal:8', 'minimum_quantity' => 'decimal:8', 'amount' => 'decimal:8'];
    }
}
