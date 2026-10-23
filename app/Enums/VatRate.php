<?php

namespace App\Enums;

use App\Support\Money;

/**
 * UK VAT treatments for a line. Exempt and outside the scope both charge
 * nothing but are reported separately.
 */
enum VatRate: string
{
    case Standard = '20';
    case Reduced = '5';
    case Zero = '0';
    case Exempt = 'exempt';
    case OutsideScope = 'outside-scope';

    public function label(): string
    {
        return match ($this) {
            self::Standard => '20%',
            self::Reduced => '5%',
            self::Zero => '0%',
            self::Exempt => 'Exempt',
            self::OutsideScope => 'Outside the scope',
        };
    }

    /**
     * Basis points: 2000 is 20%.
     */
    public function basisPoints(): int
    {
        return match ($this) {
            self::Standard => 2000,
            self::Reduced => 500,
            default => 0,
        };
    }

    public function on(Money $net): Money
    {
        return $net->percentage($this->basisPoints());
    }
}
