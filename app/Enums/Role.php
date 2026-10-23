<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super-admin';
    case OperationsDirector = 'operations-director';
    case HeadOfDepartment = 'head-of-department';
    case Producer = 'producer';
    case Finance = 'finance';
    case Hr = 'hr';
    case Employee = 'employee';
    case Freelancer = 'freelancer';
    case ClientViewer = 'client-viewer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
            self::OperationsDirector => 'Operations director',
            self::HeadOfDepartment => 'Head of department',
            self::Producer => 'Producer',
            self::Finance => 'Finance',
            self::Hr => 'HR',
            self::Employee => 'Employee',
            self::Freelancer => 'Freelancer',
            self::ClientViewer => 'Client viewer',
        };
    }

    /**
     * Roles in the order they are listed in admin screens.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }
}
