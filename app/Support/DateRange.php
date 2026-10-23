<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * An inclusive range of calendar dates. Event and booking dates are plain
 * dates, so no timezone conversion happens here.
 */
final class DateRange
{
    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    public function __construct(CarbonImmutable|string $start, CarbonImmutable|string $end)
    {
        $this->start = (is_string($start) ? CarbonImmutable::parse($start) : $start)->startOfDay();
        $this->end = (is_string($end) ? CarbonImmutable::parse($end) : $end)->startOfDay();

        if ($this->end->lessThan($this->start)) {
            throw new InvalidArgumentException('A date range cannot end before it starts.');
        }
    }

    public static function of(string $start, ?string $end = null): self
    {
        return new self($start, $end ?? $start);
    }

    public function overlaps(self $other): bool
    {
        return $this->start->lessThanOrEqualTo($other->end) && $other->start->lessThanOrEqualTo($this->end);
    }

    public function intersect(self $other): ?self
    {
        if (! $this->overlaps($other)) {
            return null;
        }

        return new self($this->start->max($other->start), $this->end->min($other->end));
    }

    public function contains(CarbonImmutable|string $date): bool
    {
        $day = (is_string($date) ? CarbonImmutable::parse($date) : $date)->startOfDay();

        return $day->betweenIncluded($this->start, $this->end);
    }

    public function lengthInDays(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /**
     * @return list<string> Y-m-d strings, in order
     */
    public function dates(): array
    {
        $dates = [];

        for ($day = $this->start; $day->lessThanOrEqualTo($this->end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * "23 Oct 2026", "22–23 Oct 2026", "30 Nov – 4 Dec 2026".
     */
    public function format(): string
    {
        if ($this->start->equalTo($this->end)) {
            return $this->start->format('j M Y');
        }

        if ($this->start->year !== $this->end->year) {
            return $this->start->format('j M Y').' – '.$this->end->format('j M Y');
        }

        if ($this->start->month === $this->end->month) {
            return $this->start->format('j').'–'.$this->end->format('j M Y');
        }

        return $this->start->format('j M').' – '.$this->end->format('j M Y');
    }
}
