<?php

namespace Database\Factories;

use App\Enums\SupplierCategory;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->company(), 'category' => SupplierCategory::AvHire, 'payment_terms_days' => 30, 'is_approved' => true];
    }
}
