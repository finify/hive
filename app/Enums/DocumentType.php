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
    case SelfBillingAgreement = 'self-billing-agreement';
    case Brief = 'brief';
    case FloorPlan = 'floor-plan';
    case RunOfShow = 'run-of-show';
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
            self::SelfBillingAgreement => 'Self-billing agreement',
            self::Brief => 'Client brief',
            self::FloorPlan => 'Floor plan',
            self::RunOfShow => 'Run of show',
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
     * Types offered when uploading for a person.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if (! $case->isEventFile()) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }

    public function isEventFile(): bool
    {
        return in_array($this, [self::Brief, self::FloorPlan, self::RunOfShow], true);
    }

    /**
     * Types offered when uploading to an event. A contract here is the signed
     * client contract that lets the event move to Confirmed.
     *
     * @return array<string, string>
     */
    public static function eventOptions(): array
    {
        return [
            self::Contract->value => 'Signed client contract',
            self::Brief->value => self::Brief->label(),
            self::FloorPlan->value => self::FloorPlan->label(),
            self::RunOfShow->value => self::RunOfShow->label(),
            self::Other->value => self::Other->label(),
        ];
    }
}
