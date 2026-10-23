<?php

namespace App\Enums;

enum DocumentType: string
{
    case Contract = 'contract';
    case RightToWork = 'right-to-work';
    case PublicLiabilityInsurance = 'public-liability-insurance';
    case ProfessionalIndemnityInsurance = 'professional-indemnity-insurance';
    case Qualification = 'qualification';
    case Nda = 'nda';
    case Ir35Determination = 'ir35-determination';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Contract',
            self::RightToWork => 'Right to work check record',
            self::PublicLiabilityInsurance => 'Public liability insurance',
            self::ProfessionalIndemnityInsurance => 'Professional indemnity insurance',
            self::Qualification => 'Qualification or certificate',
            self::Nda => 'NDA',
            self::Ir35Determination => 'IR35 determination',
            self::Other => 'Other',
        };
    }

    /**
     * Insurance and right to work records are mandatory for freelancers: when
     * they expire, bookings warn or block (config hive.documents).
     */
    public function isMandatory(): bool
    {
        return in_array($this, [self::PublicLiabilityInsurance, self::RightToWork, self::Contract], true);
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
