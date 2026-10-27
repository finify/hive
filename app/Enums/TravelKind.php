<?php

namespace App\Enums;

enum TravelKind: string implements HasTone
{
    case Outbound = 'outbound';
    case Return = 'return';
    case Ground = 'ground';
    case Accommodation = 'accommodation';

    public function label(): string
    {
        return match ($this) {
            self::Outbound => 'Outbound',
            self::Return => 'Return',
            self::Ground => 'Ground transport',
            self::Accommodation => 'Accommodation',
        };
    }

    public function tone(): Tone
    {
        return Tone::Neutral;
    }

    /** The budget category the cost goes to. */
    public function costCategory(): string
    {
        return $this === self::Accommodation ? 'accommodation' : 'travel';
    }

    /**
     * @return list<TravelMode>
     */
    public function modes(): array
    {
        return match ($this) {
            self::Accommodation => [TravelMode::Hotel],
            self::Ground => [TravelMode::Car, TravelMode::Coach, TravelMode::Taxi, TravelMode::Rail],
            default => [TravelMode::Flight, TravelMode::Rail, TravelMode::Car, TravelMode::Coach, TravelMode::Ferry],
        };
    }
}
