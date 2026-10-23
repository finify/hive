<?php

namespace App\Enums;

interface HasTone
{
    public function label(): string;

    public function tone(): Tone;
}
