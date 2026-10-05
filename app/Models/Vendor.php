<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vendor extends Model
{
    use HasFactory;

    public const TYPES = ['shipping_line' => 'Shipping line', 'freight_forwarder' => 'Freight forwarder', 'consolidator' => 'Consolidator', 'transporter' => 'Transporter', 'other' => 'Other'];

    public const SERVICES = ['LCL', 'FCL', 'Pickup', 'Delivery', 'Clearance', 'Insurance'];

    public const CHANNELS = ['email' => 'Email', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'other' => 'Other'];

    protected $fillable = ['is_demo', 'company_name', 'display_name', 'type', 'services', 'coverage', 'minimum_notes', 'communication_channel', 'internal_notes', 'is_active'];

    protected function casts(): array
    {
        return ['services' => 'array', 'is_active' => 'boolean', 'is_demo' => 'boolean'];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class)->where('is_primary', true)->where('is_active', true);
    }
}
