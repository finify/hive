<?php

namespace App\Enums;

enum TravelStatus: string implements HasTone
{
    case Booked = 'booked';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): Tone
    {
        return $this === self::Booked ? Tone::Positive : Tone::Neutral;
    }
}
