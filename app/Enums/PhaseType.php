<?php

namespace App\Enums;

enum PhaseType: string
{
    case Travel = 'travel';
    case Build = 'build';
    case Rehearsal = 'rehearsal';
    case Live = 'live';
    case Breakdown = 'breakdown';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
