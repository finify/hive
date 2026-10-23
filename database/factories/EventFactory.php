<?php

namespace Database\Factories;

use App\Enums\EventStage;
use App\Enums\EventType;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'EV-27'.fake()->unique()->numberBetween(100, 999),
            'name' => fake()->company().' Summit',
            'event_type' => EventType::Conference,
            'stage' => EventStage::Enquiry,
            'city' => 'London',
            'country' => 'United Kingdom',
            'start_date' => '2027-03-10',
            'end_date' => '2027-03-11',
            'currency' => 'GBP',
        ];
    }
}
