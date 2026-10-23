<?php

namespace App\Enums;

enum EntryStatus: string implements HasTone
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Returned => 'Returned',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Pending => Tone::Warning,
            self::Approved => Tone::Positive,
            self::Returned => Tone::Negative,
        };
    }
}
