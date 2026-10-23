<?php

namespace App\Support\Finance;

use App\Enums\HealthStatus;
use App\Support\Money;

/**
 * On track: forecast within budget. Watch: forecast over the watch share of
 * budget, or margin below target. At risk: forecast over budget.
 */
final class HealthCalculator
{
    public function __construct(
        private readonly float $watchRatio,
        private readonly float $targetMarginPercent,
    ) {}

    public static function fromConfig(): self
    {
        return new self((float) config('hive.health.watch_forecast_ratio'), (float) config('hive.health.target_margin_percent'));
    }

    public function status(Money $budget, Money $forecast, Money $revenue): HealthStatus
    {
        if ($budget->isZero()) {
            return HealthStatus::NoBudget;
        }

        if ($forecast->greaterThan($budget)) {
            return HealthStatus::AtRisk;
        }

        // Compare in integers: forecast * 10000 > budget * ratio * 10000.
        $watchLine = (int) round($budget->minor * $this->watchRatio * 10_000);

        if ($forecast->minor * 10_000 > $watchLine) {
            return HealthStatus::Watch;
        }

        if ($revenue->isPositive()) {
            $margin = $revenue->minus($forecast);

            if ($margin->minor * 100 < $revenue->minor * $this->targetMarginPercent) {
                return HealthStatus::Watch;
            }
        }

        return HealthStatus::OnTrack;
    }
}
