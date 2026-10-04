<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return ['company_name' => fake()->unique()->company(), 'type' => 'freight_forwarder', 'services' => ['LCL', 'FCL'], 'communication_channel' => 'email', 'is_active' => true];
    }
}
