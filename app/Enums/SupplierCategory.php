<?php

namespace App\Enums;

enum SupplierCategory: string
{
    case AvHire = 'av-hire';
    case Staging = 'staging';
    case Catering = 'catering';
    case Venue = 'venue';
    case Print = 'print';
    case Transport = 'transport';
    case Accommodation = 'accommodation';
    case Freight = 'freight';
    case StaffingAgency = 'staffing-agency';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AvHire => 'AV hire',
            self::Staging => 'Staging',
            self::Catering => 'Catering',
            self::Venue => 'Venue',
            self::Print => 'Print',
            self::Transport => 'Transport',
            self::Accommodation => 'Accommodation',
            self::Freight => 'Freight',
            self::StaffingAgency => 'Staffing agency',
            self::Other => 'Other',
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
}
