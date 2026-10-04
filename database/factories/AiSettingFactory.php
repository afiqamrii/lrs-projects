<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AiSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => 1, 'enabled' => false, 'model' => null, 'configuration' => [], 'model_check' => null];
    }
}
