<?php

use App\Support\DateRange;
use App\Support\Resourcing\ShiftPattern;
use App\Support\Time\WorkingTimeRules;
use Carbon\CarbonImmutable;

it('flags an average week above the limit over the reference period, not one busy week', function () {
    $pattern = new ShiftPattern(new WorkingTimeRules, 48, 20, 17);
    $week = fn (int $n) => ['id' => $n, 'dates' => DateRange::of(
        CarbonImmutable::parse('2026-07-06')->addWeeks($n)->toDateString(),
        CarbonImmutable::parse('2026-07-06')->addWeeks($n)->addDays(5)->toDateString(),
    ), 'call_time' => '08:00', 'wrap_time' => '18:00'];

    // One 53-hour week is fine on average.
    expect($pattern->warnings([$week(0)]))->toBe([]);

    // Seventeen six-day weeks of 9.7 hours average 58 hours.
    $warnings = $pattern->warnings(array_map($week, range(0, 16)));
    expect($warnings[16][0])->toStartWith('Averaging 58 hours a week over the 17 weeks to');
});

it('flags short rest across two bookings', function () {
    $warnings = (new ShiftPattern(new WorkingTimeRules, 48, 20))->warnings([
        ['id' => 'a', 'dates' => DateRange::of('2026-11-02'), 'call_time' => '12:00', 'wrap_time' => '23:00'],
        ['id' => 'b', 'dates' => DateRange::of('2026-11-03'), 'call_time' => '06:00', 'wrap_time' => '14:00'],
    ]);

    expect($warnings)->toBe(['b' => ['3 Nov: Only 7 hours’ rest since the previous shift']]);
});
