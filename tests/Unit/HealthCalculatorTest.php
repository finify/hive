<?php

use App\Enums\HealthStatus;
use App\Support\Finance\HealthCalculator;
use App\Support\Money;

$calc = new HealthCalculator(0.90, 18);

it('follows the configured thresholds (rule 14)', function (int $budget, int $forecast, int $revenue, HealthStatus $expected) use ($calc) {
    expect($calc->status(Money::of($budget), Money::of($forecast), Money::of($revenue)))->toBe($expected);
})->with([
    'well within budget and margin' => [100_000, 80_000, 130_000, HealthStatus::OnTrack],
    'exactly 90% of budget' => [100_000, 90_000, 130_000, HealthStatus::OnTrack],
    'just over 90% of budget' => [100_000, 90_001, 130_000, HealthStatus::Watch],
    'margin below 18%' => [100_000, 80_000, 95_000, HealthStatus::Watch],
    'forecast over budget' => [100_000, 100_001, 200_000, HealthStatus::AtRisk],
    'no budget' => [0, 0, 0, HealthStatus::NoBudget],
]);
