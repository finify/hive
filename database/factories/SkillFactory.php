<?php

namespace Database\Factories;

use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(2, true), 'category' => fake()->randomElement(['Video', 'Audio', 'Lighting', 'Show'])];
    }
}
