<?php

use App\Actions\People\AddDayRate;
use App\Models\Person;
use App\Support\Finance\RateBook;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

it('ends the current rate the day before a new one starts', function () {
    $kofi = Person::query()->findOrFail(26);

    app(AddDayRate::class)->handle(persona('grace'), $kofi, ['role_name' => 'AV Technician', 'amount' => '425', 'effective_from' => '2027-01-01']);

    $rates = $kofi->dayRates()->get();
    expect($rates)->toHaveCount(3)
        ->and($rates[1]->effective_to->toDateString())->toBe('2026-12-31')
        ->and($rates[2]->amount_minor)->toBe(42_500)
        ->and($rates[2]->effective_to)->toBeNull();

    $book = new RateBook($this->workspace()->person(26)['day_rates']);
    expect($book->rateOn('2026-12-31')->minor)->toBe(40_000)
        ->and($book->rateOn('2027-01-01')->minor)->toBe(42_500);
});

it('refuses a new rate that starts before the current one', function () {
    app(AddDayRate::class)->handle(persona('grace'), Person::query()->findOrFail(26), ['role_name' => 'AV Technician', 'amount' => '425', 'effective_from' => '2026-09-01']);
})->throws(ValidationException::class);

it('lets only rates.edit add rates', function (string $who) {
    app(AddDayRate::class)->handle(persona($who), Person::query()->findOrFail(26), ['role_name' => 'AV Technician', 'amount' => '425', 'effective_from' => '2027-01-01']);
})->with(['leah', 'hannah', 'kofi'])->throws(AuthorizationException::class);

it('logs rate changes with before and after', function () {
    app(AddDayRate::class)->handle(persona('grace'), Person::query()->findOrFail(26), ['role_name' => 'AV Technician', 'amount' => '425', 'effective_from' => '2027-01-01']);

    $this->assertDatabaseHas('activity_log', ['event' => 'rate-added', 'causer_id' => persona('grace')->id]);
});
