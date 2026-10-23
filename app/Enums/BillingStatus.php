<?php

namespace App\Enums;

enum BillingStatus: string implements HasTone
{
    case Planned = 'planned';
    case Due = 'due';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Due => 'Due',
            self::Invoiced => 'Invoiced',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Planned => Tone::Neutral,
            self::Due => Tone::Warning,
            self::Invoiced => Tone::Info,
            self::Paid => Tone::Positive,
            self::Overdue => Tone::Negative,
        };
    }
}
