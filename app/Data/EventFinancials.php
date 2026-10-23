<?php

namespace App\Data;

use App\Enums\HealthStatus;
use App\Support\Money;

final class EventFinancials
{
    public function __construct(
        public readonly Money $revenue,
        public readonly Money $budget,
        public readonly Money $committed,
        public readonly Money $actual,
        public readonly Money $remaining,
        public readonly ?HealthStatus $health,
    ) {}

    public function forecast(): Money
    {
        return $this->actual->plus($this->committed)->plus($this->remaining);
    }

    public function margin(): Money
    {
        return $this->revenue->minus($this->forecast());
    }

    /**
     * Margin as a percentage of revenue, to one decimal place.
     */
    public function marginPercent(): ?float
    {
        if ($this->revenue->isZero()) {
            return null;
        }

        return round($this->margin()->minor * 100 / $this->revenue->minor, 1);
    }

    public function variance(): Money
    {
        return $this->budget->minus($this->forecast());
    }

    /**
     * Share of budget the forecast uses, as a whole-number percentage.
     */
    public function forecastPercentOfBudget(): ?int
    {
        if ($this->budget->isZero()) {
            return null;
        }

        return (int) round($this->forecast()->minor * 100 / $this->budget->minor);
    }
}
