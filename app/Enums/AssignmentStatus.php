<?php

namespace App\Enums;

enum AssignmentStatus: string implements HasTone
{
    case SecondHold = 'second-hold';
    case FirstHold = 'first-hold';
    case Confirmed = 'confirmed';
    case Released = 'released';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::SecondHold => 'Second hold',
            self::FirstHold => 'First hold',
            self::Confirmed => 'Confirmed',
            self::Released => 'Released',
            self::Declined => 'Declined',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::SecondHold, self::Released => Tone::Neutral,
            self::FirstHold => Tone::Warning,
            self::Confirmed => Tone::Positive,
            self::Declined => Tone::Negative,
        };
    }

    /**
     * Released and declined bookings no longer hold the person.
     */
    public function holdsPerson(): bool
    {
        return ! in_array($this, [self::Released, self::Declined], true);
    }
}
