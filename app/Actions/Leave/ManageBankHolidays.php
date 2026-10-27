<?php

namespace App\Actions\Leave;

use App\Models\BankHoliday;
use App\Models\User;
use App\Support\Display;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * HR adds or removes bank holidays for a region. Leave already requested
 * keeps the days it was counted with.
 */
class ManageBankHolidays
{
    /**
     * @param  array{region?: mixed, date?: mixed, name?: mixed}  $input
     */
    public function add(User $user, array $input): BankHoliday
    {
        Gate::forUser($user)->authorize('leave.manage');

        $data = Validator::make($input, [
            'region' => ['required', Rule::in(array_keys(BankHoliday::REGIONS))],
            'date' => ['required', 'date', function (string $attribute, mixed $value, \Closure $fail) use ($input) {
                if (BankHoliday::query()->where('region', $input['region'] ?? null)->whereDate('date', (string) $value)->exists()) {
                    $fail('That region already has a bank holiday on that date.');
                }
            }],
            'name' => ['required', 'string', 'max:100'],
        ])->validate();

        $holiday = BankHoliday::query()->create($data);

        activity('people')
            ->causedBy($user)
            ->performedOn($holiday)
            ->withProperties(['ref' => 'BH-'.$holiday->id, 'attributes' => $data])
            ->event('bank-holiday-added')
            ->log("Added {$data['name']}, ".Display::date($data['date']).' ('.BankHoliday::REGIONS[$data['region']].')');

        return $holiday;
    }

    public function remove(User $user, int $id): void
    {
        Gate::forUser($user)->authorize('leave.manage');

        $holiday = BankHoliday::query()->findOrFail($id);
        $holiday->delete();

        activity('people')
            ->causedBy($user)
            ->withProperties(['ref' => 'BH-'.$id, 'old' => ['region' => $holiday->region, 'date' => $holiday->date->toDateString(), 'name' => $holiday->name]])
            ->event('bank-holiday-removed')
            ->log("Removed {$holiday->name}, ".Display::date($holiday->date->toDateString()));
    }
}
