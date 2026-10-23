<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->company(), 'sector' => 'Technology', 'payment_terms_days' => 30];
    }
}
