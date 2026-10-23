<?php

namespace App\Enums;

enum CostKind: string
{
    case Committed = 'committed';
    case Actual = 'actual';
}
