<?php

namespace App\Enums;

use App\Models\Person;
use App\Models\User;

/**
 * Who ticks off a checklist item. HR can tick anything.
 */
enum ChecklistOwner: string implements HasTone
{
    case Hr = 'hr';
    case It = 'it';
    case LineManager = 'line-manager';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::Hr => 'HR',
            self::It => 'IT',
            self::LineManager => 'Line manager',
            self::Finance => 'Finance',
        };
    }

    public function tone(): Tone
    {
        return Tone::Neutral;
    }

    public function canTick(User $user, Person $person): bool
    {
        if ($user->can('people.manage')) {
            return true;
        }

        return match ($this) {
            self::Hr => false,
            self::It => $user->can('users.manage'),
            self::LineManager => $user->person_id !== null && $person->line_manager_id === $user->person_id,
            self::Finance => $user->can('rates.edit'),
        };
    }
}
