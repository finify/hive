<?php

namespace App\Enums;

enum SupplierInvoiceStatus: string implements HasTone
{
    case Received = 'received';
    case Matched = 'matched';
    case Approved = 'approved';
    case Paid = 'paid';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Matched => 'Matched',
            self::Approved => 'Approved for payment',
            self::Paid => 'Paid',
            self::Disputed => 'Disputed',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Received => Tone::Neutral,
            self::Matched => Tone::Info,
            self::Approved, self::Paid => Tone::Positive,
            self::Disputed => Tone::Negative,
        };
    }
}
