<?php

namespace Database\Factories;

use App\Enums\EmploymentType;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'employment_type' => EmploymentType::Employee,
            'job_title' => 'Producer',
            'location_city' => 'London',
            'location_country' => 'United Kingdom',
            'start_date' => '2024-01-08',
            'languages' => ['English'],
            'is_active' => true,
        ];
    }

    public function freelancer(): static
    {
        return $this->state(fn () => [
            'employment_type' => EmploymentType::Freelancer,
            'job_title' => 'AV Technician',
            'payment_terms_days' => 30,
        ]);
    }
}
