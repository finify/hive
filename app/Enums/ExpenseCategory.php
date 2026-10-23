<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Travel = 'travel';
    case Subsistence = 'subsistence';
    case Taxi = 'taxi';
    case Parking = 'parking';
    case Mileage = 'mileage';
    case Materials = 'materials';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Travel => 'Travel',
            self::Subsistence => 'Subsistence',
            self::Taxi => 'Taxi',
            self::Parking => 'Parking',
            self::Mileage => 'Mileage',
            self::Materials => 'Materials',
            self::Other => 'Other',
        };
    }

    /**
     * The budget category an approved expense is charged to.
     */
    public function costCategory(): string
    {
        return match ($this) {
            self::Travel, self::Taxi, self::Parking, self::Mileage => 'travel',
            self::Subsistence => 'catering',
            self::Materials => 'production',
            self::Other => 'contingency',
        };
    }
}
