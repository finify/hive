<?php

namespace App\Enums;

enum LeaveStatus: string implements HasTone
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Pending => Tone::Warning,
            self::Approved => Tone::Positive,
            self::Declined => Tone::Negative,
            self::Cancelled => Tone::Neutral,
        };
    }
}
