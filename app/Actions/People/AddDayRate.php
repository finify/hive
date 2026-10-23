<?php

namespace App\Actions\People;

use App\Models\DayRate;
use App\Models\Person;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Adds an effective-dated day rate. The current rate for the same role ends
 * the day before; costs already posted keep their amounts (rule 8).
 */
class AddDayRate
{
    /**
     * @param  array{role_name?: string, amount?: string, effective_from?: string}  $input
     */
    public function handle(User $user, Person $person, array $input): DayRate
    {
        Gate::forUser($user)->authorize('addRate', $person);

        $data = Validator::make($input, [
            'role_name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'effective_from' => ['required', 'date'],
        ], ['amount.regex' => 'Enter the day rate in pounds, for example 400 or 412.50.'])->validate();

        $from = CarbonImmutable::parse($data['effective_from']);
        $current = $person->dayRates()->where('role_name', $data['role_name'])->whereNull('effective_to')->first();

        if ($current && $from->lessThanOrEqualTo($current->effective_from)) {
            throw ValidationException::withMessages(['effective_from' => 'The new rate must start after '.$current->effective_from->format('j M Y').', when the current rate began.']);
        }

        return DB::transaction(function () use ($person, $current, $from, $data, $user) {
            $current?->update(['effective_to' => $from->subDay()->toDateString()]);

            $rate = $person->dayRates()->create([
                'role_name' => $data['role_name'],
                'amount_minor' => Money::parse($data['amount'])->minor,
                'currency' => 'GBP',
                'effective_from' => $from->toDateString(),
            ]);

            activity('people')
                ->causedBy($user)
                ->performedOn($person)
                ->withProperties([
                    'ref' => 'P-'.$person->id,
                    'old' => ['day_rate' => $current?->amount->format()],
                    'attributes' => ['day_rate' => $rate->amount->format(), 'effective_from' => $from->toDateString()],
                ])
                ->event('rate-added')
                ->log("New {$data['role_name']} day rate for {$person->displayName()} from {$from->format('j M Y')}");

            return $rate;
        });
    }
}
