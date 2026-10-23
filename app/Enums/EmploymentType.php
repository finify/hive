<?php

namespace App\Enums;

enum EmploymentType: string
{
    case Employee = 'employee';
    case FixedTerm = 'fixed-term';
    case Freelancer = 'freelancer';
    case AgencyTemp = 'agency-temp';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Employee',
            self::FixedTerm => 'Fixed-term',
            self::Freelancer => 'Freelancer',
            self::AgencyTemp => 'Agency temp',
        };
    }

    public function isFreelance(): bool
    {
        return $this === self::Freelancer;
    }
}
