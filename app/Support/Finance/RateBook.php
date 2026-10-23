<?php

namespace App\Support\Finance;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Looks up the day rate effective on a date. Rates are effective-dated, so a
 * change never alters costs already posted (they stored their own amount).
 */
final class RateBook
{
    /**
     * @param  list<array{role_name?: string, amount_minor: int, currency: string, effective_from: string, effective_to: ?string}>  $rates
     */
    public function __construct(private readonly array $rates) {}

    public function rateOn(CarbonImmutable|string $date, ?string $role = null): ?Money
    {
        $day = is_string($date) ? $date : $date->toDateString();

        foreach ($this->rates as $rate) {
            if ($role !== null && isset($rate['role_name']) && $rate['role_name'] !== $role) {
                continue;
            }

            $from = $rate['effective_from'];
            $to = $rate['effective_to'];

            if ($from <= $day && ($to === null || $day <= $to)) {
                return Money::of($rate['amount_minor'], $rate['currency']);
            }
        }

        return null;
    }

    /**
     * Cost of a number of days worked, where days may be a half day.
     */
    public function costFor(CarbonImmutable|string $date, float $days, ?string $role = null): ?Money
    {
        $rate = $this->rateOn($date, $role);

        // Days are recorded in halves, so scale by two to stay in integers.
        return $rate?->multiplyRatio((int) round($days * 2), 2);
    }
}
