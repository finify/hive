<?php

namespace App\Enums;

enum HealthStatus: string implements HasTone
{
    case OnTrack = 'on-track';
    case Watch = 'watch';
    case AtRisk = 'at-risk';
    case NoBudget = 'no-budget';

    public function label(): string
    {
        return match ($this) {
            self::OnTrack => 'On track',
            self::Watch => 'Watch',
            self::AtRisk => 'At risk',
            self::NoBudget => 'No budget yet',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::OnTrack => Tone::Positive,
            self::Watch => Tone::Warning,
            self::AtRisk => Tone::Negative,
            self::NoBudget => Tone::Neutral,
        };
    }
}
