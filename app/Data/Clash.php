<?php

namespace App\Data;

use App\Support\DateRange;

final class Clash
{
    public function __construct(
        public readonly int $personId,
        public readonly DateRange $dates,
        public readonly Booking $first,
        public readonly Booking $second,
    ) {}

    public function key(): string
    {
        $keys = [$this->first->key(), $this->second->key()];
        sort($keys);

        return implode('|', $keys);
    }

    /**
     * Two confirmed assignments with a recorded override reason are a known,
     * accepted overlap rather than an open clash.
     */
    public function isOverridden(): bool
    {
        return $this->first->overrideReason !== null || $this->second->overrideReason !== null;
    }

    public function involvesLeave(): bool
    {
        return $this->first->kind !== Booking::ASSIGNMENT || $this->second->kind !== Booking::ASSIGNMENT;
    }

    /**
     * @return list<int>
     */
    public function eventIds(): array
    {
        return array_values(array_filter([$this->first->eventId, $this->second->eventId]));
    }
}
