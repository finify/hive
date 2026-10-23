<?php

use App\Support\Finance\InvoiceTolerance;
use App\Support\Money;

$tolerance = new InvoiceTolerance(5, 25_000);

it('allows 5% or £250, whichever is lower', function () use ($tolerance) {
    expect($tolerance->allowance(Money::of(1_840_000))->minor)->toBe(25_000)
        ->and($tolerance->allowance(Money::of(200_000))->minor)->toBe(10_000);
});

it('flags variances over the allowance in either direction (rule 11)', function () use ($tolerance) {
    expect($tolerance->isOverTolerance(Money::of(1_840_000), Money::of(1_965_000)))->toBeTrue()
        ->and($tolerance->isOverTolerance(Money::of(1_840_000), Money::of(1_865_000)))->toBeFalse()
        ->and($tolerance->isOverTolerance(Money::of(1_840_000), Money::of(1_800_000)))->toBeTrue();
});
