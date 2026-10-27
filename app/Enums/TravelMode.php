<?php

namespace App\Enums;

enum TravelMode: string implements HasTone
{
    case Flight = 'flight';
    case Rail = 'rail';
    case Car = 'car';
    case Coach = 'coach';
    case Taxi = 'taxi';
    case Ferry = 'ferry';
    case Hotel = 'hotel';

    public function label(): string
    {
        return match ($this) {
            self::Flight => 'Flight',
            self::Rail => 'Rail',
            self::Car => 'Car',
            self::Coach => 'Coach',
            self::Taxi => 'Taxi',
            self::Ferry => 'Ferry',
            self::Hotel => 'Hotel',
        };
    }

    public function tone(): Tone
    {
        return Tone::Neutral;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Flight => 'airplane',
            self::Rail => 'train-front',
            self::Car, self::Taxi => 'car-front',
            self::Coach => 'bus-front',
            self::Ferry => 'water',
            self::Hotel => 'building',
        };
    }
}
