<?php

namespace App\Enums;

enum RevenueType: string
{
    case Fee = 'fee';
    case PassThrough = 'pass-through';
    case ManagementFee = 'management-fee';
    case ChangeRequest = 'change-request';

    public function label(): string
    {
        return match ($this) {
            self::Fee => 'Fee',
            self::PassThrough => 'Pass-through costs',
            self::ManagementFee => 'Management fee',
            self::ChangeRequest => 'Change request',
        };
    }
}
