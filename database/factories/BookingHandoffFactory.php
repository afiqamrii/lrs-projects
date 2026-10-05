<?php

namespace Database\Factories;

use App\Models\BookingHandoff;
use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BookingHandoff> */
class BookingHandoffFactory extends Factory
{
    /** Deliberately incomplete persistence fixtures. Accepted commercial stories must use reviewed workflow actions; child factories require their explicit source parent. */
    public function definition(): array
    {
        return ['inquiry_id' => Inquiry::factory(), 'current_number' => 0];
    }
}
