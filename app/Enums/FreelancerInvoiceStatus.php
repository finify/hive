<?php

namespace App\Enums;

enum FreelancerInvoiceStatus: string implements HasTone
{
    case Received = 'received';
    case Approved = 'approved';
    case Paid = 'paid';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Approved => 'Approved for payment',
            self::Paid => 'Paid',
            self::Disputed => 'Disputed',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Received => Tone::Info,
            self::Approved => Tone::Positive,
            self::Paid => Tone::Neutral,
            self::Disputed => Tone::Negative,
        };
    }
}
