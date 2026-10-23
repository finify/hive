<?php

use App\Data\Booking;
use App\Support\DateRange;
use App\Support\Resourcing\ClashDetector;

function assignment(int $id, int $person, int $event, string $from, string $to, ?string $reason = null): Booking
{
    return new Booking(Booking::ASSIGNMENT, $id, $person, DateRange::of($from, $to), eventId: $event, confirmed: true, overrideReason: $reason, label: "EV-{$event}");
}

it('finds two bookings for one person on overlapping dates', function () {
    $clashes = (new ClashDetector)->detect([
        assignment(1, 26, 7, '2026-10-20', '2026-10-24'),
        assignment(2, 26, 8, '2026-10-23', '2026-10-23'),
        assignment(3, 31, 7, '2026-10-20', '2026-10-24'),
    ]);

    expect($clashes)->toHaveCount(1)
        ->and($clashes[0]->personId)->toBe(26)
        ->and($clashes[0]->dates->format())->toBe('23 Oct 2026');
});

it('treats an override reason as an accepted overlap (rule 1)', function () {
    $clashes = (new ClashDetector)->detect([
        assignment(1, 26, 7, '2026-10-20', '2026-10-24'),
        assignment(2, 26, 8, '2026-10-23', '2026-10-23', 'Morning build only, evening show'),
    ]);

    expect($clashes)->toHaveCount(1)->and($clashes[0]->isOverridden())->toBeTrue();
});

it('flags a booking over approved leave', function () {
    $clashes = (new ClashDetector)->detect([
        assignment(1, 16, 10, '2026-11-05', '2026-11-05'),
        new Booking(Booking::LEAVE, 2, 16, DateRange::of('2026-11-04', '2026-11-06')),
    ]);

    expect($clashes)->toHaveCount(1)->and($clashes[0]->involvesLeave())->toBeTrue();
});

it('does not count two bookings on the same event, or leave against unavailability', function () {
    $clashes = (new ClashDetector)->detect([
        assignment(1, 26, 7, '2026-10-20', '2026-10-21'),
        assignment(2, 26, 7, '2026-10-21', '2026-10-24'),
        new Booking(Booking::LEAVE, 3, 26, DateRange::of('2026-11-01', '2026-11-02')),
        new Booking(Booking::UNAVAILABLE, 4, 26, DateRange::of('2026-11-02', '2026-11-03')),
    ]);

    expect($clashes)->toBe([]);
});

it('checks a candidate booking against existing ones', function () {
    $existing = [assignment(1, 26, 7, '2026-10-20', '2026-10-24')];
    $detector = new ClashDetector;

    expect($detector->clashesFor(assignment(9, 26, 9, '2026-10-24', '2026-10-25'), $existing))->toHaveCount(1)
        ->and($detector->clashesFor(assignment(9, 26, 9, '2026-10-25', '2026-10-26'), $existing))->toBe([]);
});
