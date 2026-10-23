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

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public function isFreelance(): bool
    {
        return $this === self::Freelancer;
    }
}
