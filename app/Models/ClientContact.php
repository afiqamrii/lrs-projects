<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientContact extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'name', 'email', 'phone', 'role', 'is_active', 'is_primary'];

    protected $attributes = ['is_active' => true, 'is_primary' => false];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_primary' => 'boolean'];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
