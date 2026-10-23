<?php

namespace Database\Factories;

use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->company().' Hall', 'address' => fake()->streetAddress(), 'city' => 'London', 'country' => 'United Kingdom', 'capacity' => 500];
    }
}
