<?php

namespace App\Enums;

/**
 * The semantic colour of a status pill. Colour is never the only signal: a
 * pill always carries its text.
 */
enum Tone: string
{
    case Positive = 'positive';
    case Warning = 'warning';
    case Negative = 'negative';
    case Neutral = 'neutral';
    case Info = 'info';
}
