<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Model
{
    use HasFactory;

    protected $fillable = ['company_name', 'reference_identifier', 'address', 'internal_notes', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class)->orderByDesc('is_primary')->orderByDesc('is_active')->orderBy('name');
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(ClientContact::class)->where('is_active', true)->where('is_primary', true);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }
}
