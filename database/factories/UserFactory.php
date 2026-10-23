<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
        ];
    }

    /**
     * Give the user a role from section 6 of the specification.
     */
    public function withRole(Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role->value));
    }

    /**
     * A user with confirmed two-factor authentication.
     */
    public function withTwoFactor(string $secret = 'JBSWY3DPEHPK3PXP'): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-one-0000', 'code-two-0000'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
