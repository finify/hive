<?php

namespace App\Enums;

enum EventType: string
{
    case Conference = 'conference';
    case Exhibition = 'exhibition';
    case ProductLaunch = 'product-launch';
    case ExecutiveSummit = 'executive-summit';
    case IncentiveTrip = 'incentive-trip';
    case BrandActivation = 'brand-activation';
    case Hybrid = 'hybrid';
    case Virtual = 'virtual';

    public function label(): string
    {
        return match ($this) {
            self::Conference => 'Conference',
            self::Exhibition => 'Exhibition',
            self::ProductLaunch => 'Product launch',
            self::ExecutiveSummit => 'Executive summit',
            self::IncentiveTrip => 'Incentive trip',
            self::BrandActivation => 'Brand activation',
            self::Hybrid => 'Hybrid',
            self::Virtual => 'Virtual',
        };
    }
}
