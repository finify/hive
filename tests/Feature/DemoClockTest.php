<?php

use App\Demo\DemoData;
use App\Models\Person;
use Carbon\CarbonImmutable;

function demoData(): DemoData
{
    app()->forgetScopedInstances();

    return app(DemoData::class);
}

it('keeps the data’s own date on the fixed clock', function () {
    expect(demoData()->today()->toDateString())->toBe('2026-10-23')
        ->and(demoData()->shiftDays())->toBe(0)
        ->and(collect(demoData()->raw('events'))->firstWhere('code', 'EV-26114')['start_date'])->toBe('2026-10-22');
});

it('runs on today’s date and moves the data by whole weeks into this week', function () {
    config(['hive.demo.clock' => 'live']);
    $this->travelTo('2026-12-02 10:00:00'); // a Wednesday, six weeks on

    $data = demoData();
    $events = collect($data->raw('events'));

    expect($data->today()->toDateString())->toBe('2026-12-02')
        ->and($data->shiftDays())->toBe(42)
        ->and($events->firstWhere('code', 'EV-26114')['start_date'])->toBe('2026-12-03')
        ->and(collect($data->raw('timesheets'))->every(fn ($t) => CarbonImmutable::parse($t['week_start'])->isMonday()))->toBeTrue()
        ->and($data->raw('assignments')[0]['requested_at'])->toEndWith('T10:00:00Z')
        ->and($data->raw('bank_holidays')[0]['date'])->toBe('2026-01-01');
});

it('moves leave years with the dates', function () {
    config(['hive.demo.clock' => 'live']);
    $this->travelTo('2027-03-10 09:00:00');

    expect(demoData()->raw('leave_allowances')[0]['leave_year'])->toBe(2027);
});

it('uses the real date outside the demo', function () {
    config(['hive.demo.enabled' => false]);
    $this->travelTo('2027-05-04 12:00:00');

    expect(demoData()->today()->toDateString())->toBe('2027-05-04')
        ->and(demoData()->shiftDays())->toBe(0);
});

it('keeps people and documents in step when the demo refreshes in a new week', function () {
    $joel = Person::query()->findOrFail(61)->start_date->toDateString();

    config(['hive.demo.clock' => 'live']);
    $this->travelTo('2026-11-04 08:00:00'); // two weeks on
    app()->forgetScopedInstances();
    $this->artisan('hive:demo-refresh')->assertSuccessful();

    expect(Person::query()->findOrFail(61)->start_date->toDateString())->toBe(CarbonImmutable::parse($joel)->addDays(14)->toDateString());

    // A second refresh the same week moves nothing.
    $this->artisan('hive:demo-refresh')->assertSuccessful();
    expect(Person::query()->findOrFail(61)->start_date->toDateString())->toBe(CarbonImmutable::parse($joel)->addDays(14)->toDateString());
});
