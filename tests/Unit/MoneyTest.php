<?php

use App\Support\Money;

it('parses major-unit strings without floats', function () {
    expect(Money::parse('1,250.50')->minor)->toBe(125050)
        ->and(Money::parse('£0.07')->minor)->toBe(7)
        ->and(Money::parse('-12.5')->minor)->toBe(-1250)
        ->and(Money::parse('400', 'eur')->currency)->toBe('EUR');
});

it('rejects amounts it cannot read', function () {
    Money::parse('12.345');
})->throws(InvalidArgumentException::class);

it('refuses to add different currencies', function () {
    Money::of(100, 'GBP')->plus(Money::of(100, 'EUR'));
})->throws(InvalidArgumentException::class);

it('keeps totals equal to the sum of their lines (rule 15)', function () {
    // Thirds of £100 at 20% VAT: each line rounds once (667p, 667p, 667p), and
    // the VAT total is the sum of the lines shown, not 20% of the net total.
    $lines = [Money::of(3333), Money::of(3333), Money::of(3334)];
    $vat = array_map(fn (Money $m) => $m->percentage(2000), $lines);

    expect(Money::sum($lines)->minor)->toBe(10000)
        ->and(Money::sum($vat)->minor)->toBe(array_sum(array_map(fn ($m) => $m->minor, $vat)))
        ->and(Money::sum($vat)->minor)->toBe(2001);
});

it('rounds ratios half away from zero', function () {
    expect(Money::of(5)->multiplyRatio(1, 2)->minor)->toBe(3)
        ->and(Money::of(-5)->multiplyRatio(1, 2)->minor)->toBe(-3)
        ->and(Money::of(40000)->multiplyRatio(1, 2)->minor)->toBe(20000);
});

it('converts foreign currency with a decimal rate string (rule 16)', function () {
    $eur = Money::of(8_400_000, 'EUR');
    $gbp = $eur->convert('0.8650', 'GBP');

    expect($gbp->minor)->toBe(7_266_000)
        ->and($gbp->currency)->toBe('GBP')
        ->and($eur->minor)->toBe(8_400_000);
});

it('formats for UK screens', function () {
    expect(Money::of(1234567)->format())->toBe('£12,345.67')
        ->and(Money::of(1234567)->format(false))->toBe('£12,345')
        ->and(Money::of(-125000)->format())->toBe('−£1,250.00')
        ->and(Money::of(48_600_000)->formatShort())->toBe('£486k')
        ->and(Money::of(177_000_000)->formatShort())->toBe('£1.77m')
        ->and(Money::of(5000, 'EUR')->format())->toBe('€50.00');
});
