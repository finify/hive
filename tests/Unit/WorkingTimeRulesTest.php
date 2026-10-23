<?php

use App\Support\Time\WorkingTimeRules;

$rules = new WorkingTimeRules(12, 11, 6, 20);

it('flags long shifts, short rest and missing breaks', function () use ($rules) {
    $flags = $rules->flag([
        ['id' => 1, 'date' => '2026-10-13', 'start_time' => '08:00', 'end_time' => '19:00', 'break_minutes' => 60],
        ['id' => 2, 'date' => '2026-10-14', 'start_time' => '07:00', 'end_time' => '21:30', 'break_minutes' => 30],
        ['id' => 3, 'date' => '2026-10-15', 'start_time' => '07:30', 'end_time' => '19:30', 'break_minutes' => 10],
    ]);

    expect(array_column($flags[1], 'code'))->toBe([])
        ->and(array_column($flags[2], 'code'))->toBe([WorkingTimeRules::LONG_SHIFT])
        ->and(array_column($flags[3], 'code'))->toContain(WorkingTimeRules::SHORT_REST, WorkingTimeRules::NO_BREAK);
});

it('flags entries with no booking and hours far above the crew call', function () use ($rules) {
    $flags = $rules->flag([
        ['id' => 1, 'date' => '2026-10-08', 'start_time' => '08:00', 'end_time' => '20:00', 'break_minutes' => 60, 'assigned' => false, 'call_hours' => 8.0],
    ]);

    expect(array_column($flags[1], 'code'))->toBe([WorkingTimeRules::NO_ASSIGNMENT, WorkingTimeRules::ABOVE_CALL]);
});

it('handles shifts past midnight', function () {
    expect(WorkingTimeRules::workedMinutes('18:00', '02:00', 30))->toBe(450);
});
