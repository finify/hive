<?php

namespace App\Enums;

enum ChecklistKind: string implements HasTone
{
    case Onboarding = 'onboarding';
    case Offboarding = 'offboarding';

    public function label(): string
    {
        return match ($this) {
            self::Onboarding => 'Onboarding',
            self::Offboarding => 'Offboarding',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Onboarding => Tone::Info,
            self::Offboarding => Tone::Neutral,
        };
    }
}
