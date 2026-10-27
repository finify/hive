<?php

namespace App\Services\Leave;

/**
 * One person's leave for one leave year, in days. Entitlement is the
 * allowance plus days carried over; remaining takes off what is taken and
 * booked; available also takes off what is waiting for approval.
 */
final class LeaveBalance
{
    public function __construct(
        public readonly int $year,
        public readonly float $allowed,
        public readonly float $carriedOver,
        public readonly float $taken,
        public readonly float $booked,
        public readonly float $pending,
        public readonly float $toilEarned,
        public readonly float $toilUsed,
        public readonly float $toilPending,
    ) {}

    public function entitlement(): float
    {
        return $this->allowed + $this->carriedOver;
    }

    public function remaining(): float
    {
        return $this->entitlement() - $this->taken - $this->booked;
    }

    public function available(): float
    {
        return $this->remaining() - $this->pending;
    }

    public function toilRemaining(): float
    {
        return $this->toilEarned - $this->toilUsed;
    }

    public function toilAvailable(): float
    {
        return $this->toilRemaining() - $this->toilPending;
    }
}
