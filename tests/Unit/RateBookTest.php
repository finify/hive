<?php

use App\Support\Finance\RateBook;

$rates = [
    ['role_name' => 'AV Technician', 'amount_minor' => 38000, 'currency' => 'GBP', 'effective_from' => '2025-04-01', 'effective_to' => '2026-09-30'],
    ['role_name' => 'AV Technician', 'amount_minor' => 40000, 'currency' => 'GBP', 'effective_from' => '2026-10-01', 'effective_to' => null],
];

it('uses the rate effective on the date worked (rule 7)', function () use ($rates) {
    $book = new RateBook($rates);

    expect($book->rateOn('2026-09-30')->minor)->toBe(38000)
        ->and($book->rateOn('2026-10-01')->minor)->toBe(40000)
        ->and($book->rateOn('2025-01-01'))->toBeNull();
});

it('costs half days exactly', function () use ($rates) {
    expect((new RateBook($rates))->costFor('2026-10-14', 0.5)->minor)->toBe(20000)
        ->and((new RateBook($rates))->costFor('2026-10-14', 1)->minor)->toBe(40000);
});
