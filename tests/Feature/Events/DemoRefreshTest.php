<?php

use App\Enums\EventStage;
use App\Models\Event;

it('puts the demo events back to the seeded state', function () {
    Event::query()->whereKey(16)->update(['stage' => EventStage::ProposalSent]);
    Event::factory()->create(['code' => 'EV-26200']);

    $this->artisan('hive:demo-refresh')->assertSuccessful();

    expect(Event::query()->count())->toBe(18)
        ->and(Event::query()->findOrFail(16)->stage)->toBe(EventStage::Enquiry)
        ->and(Event::withTrashed()->where('code', 'EV-26200')->exists())->toBeFalse();
});

it('refuses outside demo mode', function () {
    config(['hive.demo.enabled' => false]);

    $this->artisan('hive:demo-refresh')->assertFailed();
    expect(Event::query()->count())->toBe(18);
});
