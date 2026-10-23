<?php

namespace App\Enums;

enum DocumentStatus: string implements HasTone
{
    case Valid = 'valid';
    case Expiring = 'expiring';
    case Expired = 'expired';
    case Missing = 'missing';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::Expiring => 'Expiring soon',
            self::Expired => 'Expired',
            self::Missing => 'Missing',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Valid => Tone::Positive,
            self::Expiring => Tone::Warning,
            self::Expired, self::Missing => Tone::Negative,
        };
    }
}
