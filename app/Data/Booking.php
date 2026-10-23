<?php

namespace App\Data;

use App\Support\DateRange;

/**
 * Anything that occupies a person on some dates: an assignment, approved
 * leave or marked unavailability.
 */
final class Booking
{
    public const ASSIGNMENT = 'assignment';

    public const LEAVE = 'leave';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public readonly string $kind,
        public readonly int|string $id,
        public readonly int $personId,
        public readonly DateRange $dates,
        public readonly ?int $eventId = null,
        public readonly bool $confirmed = false,
        public readonly ?string $overrideReason = null,
        public readonly string $label = '',
    ) {}

    public function key(): string
    {
        return $this->kind.':'.$this->id;
    }
}
