<?php

namespace App\Support\Finance;

use App\Support\Money;

/**
 * A supplier invoice whose net amount differs from its PO by more than the
 * tolerance (default 5% or £250, whichever is lower) needs approval.
 */
final class InvoiceTolerance
{
    public function __construct(
        private readonly int $percent,
        private readonly int $amountMinor,
    ) {}

    public static function fromConfig(): self
    {
        return new self((int) config('hive.invoice_tolerance.percent'), (int) config('hive.invoice_tolerance.amount_minor'));
    }

    public function allowance(Money $poNet): Money
    {
        $byPercent = $poNet->abs()->percentage($this->percent * 100);
        $byAmount = Money::of($this->amountMinor, $poNet->currency);

        return $byPercent->lessThan($byAmount) ? $byPercent : $byAmount;
    }

    public function isOverTolerance(Money $poNet, Money $invoiceNet): bool
    {
        return $invoiceNet->minus($poNet)->abs()->greaterThan($this->allowance($poNet));
    }
}
