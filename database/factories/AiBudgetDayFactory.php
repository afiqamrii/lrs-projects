<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AiBudgetDayFactory extends Factory
{
    public function definition(): array
    {
        return ['day' => now('UTC')->toDateString(), 'reserved' => '0', 'spent' => '0'];
    }
}
