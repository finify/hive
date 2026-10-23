<?php

namespace App\Enums;

/**
 * A budget version: Draft while being revised, Approved as the baseline, Revised once a later version is approved.
 */
enum BudgetStatus: string implements HasTone
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Revised = 'revised';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Revised => 'Superseded',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Draft => Tone::Warning,
            self::Approved => Tone::Positive,
            self::Revised => Tone::Neutral,
        };
    }
}
