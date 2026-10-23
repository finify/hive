<?php

namespace App\Enums;

enum TimesheetStatus: string implements HasTone
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Returned = 'returned';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Returned => 'Returned',
            self::Locked => 'Locked',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Draft, self::Locked => Tone::Neutral,
            self::Submitted => Tone::Warning,
            self::Approved => Tone::Positive,
            self::Returned => Tone::Negative,
        };
    }
}
