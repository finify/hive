<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::Employee,
            'token' => Str::random(64),
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
