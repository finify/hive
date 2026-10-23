<?php

use App\Support\DateRange;

it('detects overlaps inclusively', function () {
    $a = DateRange::of('2026-10-20', '2026-10-24');

    expect($a->overlaps(DateRange::of('2026-10-24')))->toBeTrue()
        ->and($a->overlaps(DateRange::of('2026-10-25', '2026-10-26')))->toBeFalse()
        ->and($a->intersect(DateRange::of('2026-10-23', '2026-10-30'))->format())->toBe('23–24 Oct 2026');
});

it('lists dates and counts days', function () {
    $range = DateRange::of('2026-10-30', '2026-11-02');

    expect($range->dates())->toBe(['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'])
        ->and($range->lengthInDays())->toBe(4)
        ->and($range->format())->toBe('30 Oct – 2 Nov 2026');
});

it('cannot end before it starts', function () {
    DateRange::of('2026-10-24', '2026-10-20');
})->throws(InvalidArgumentException::class);
