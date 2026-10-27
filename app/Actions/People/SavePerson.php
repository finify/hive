<?php

namespace App\Actions\People;

use App\Demo\Rows;
use App\Enums\EmploymentType;
use App\Models\BankHoliday;
use App\Models\Person;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates or updates a person: details, skills, restricted fields and, on
 * creation, a freelancer's starting day rate. HR (people.manage) may set the
 * first rate; later rates are added by finance through AddDayRate.
 */
class SavePerson
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input, ?Person $person = null): Person
    {
        $person === null
            ? Gate::forUser($user)->authorize('create', Person::class)
            : Gate::forUser($user)->authorize('update', $person);

        $data = Validator::make($input, $this->rules($person), [
            'skills.*.skill_id.distinct' => 'Each skill can only be listed once.',
            'rate.amount.regex' => 'Enter the day rate in pounds, for example 400 or 412.50.',
            'line_manager_id.not_in' => 'Someone can’t be their own line manager.',
        ])->validate();

        $isFreelance = EmploymentType::from($data['employment_type'])->isFreelance();
        $canRestricted = $person === null ? $user->can('people.view_restricted') : $user->can('editRestricted', $person);

        return DB::transaction(function () use ($data, $person, $isFreelance, $canRestricted, $user) {
            $creating = $person === null;
            $person ??= new Person;

            $person->fill([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'preferred_name' => $data['preferred_name'] ?? null,
                'pronouns' => $data['pronouns'] ?? null,
                'email' => mb_strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'employment_type' => $data['employment_type'],
                'department_id' => $data['department_id'] ?? null,
                'job_title' => $data['job_title'],
                'line_manager_id' => $data['line_manager_id'] ?? null,
                'location_city' => $data['location_city'],
                'location_country' => $data['location_country'],
                'bank_holiday_region' => $data['bank_holiday_region'] ?? 'england-and-wales',
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'company_name' => $isFreelance ? ($data['company_name'] ?? null) : null,
                'vat_registered' => $isFreelance && ($data['vat_registered'] ?? false),
                'vat_number' => $isFreelance && ($data['vat_registered'] ?? false) ? ($data['vat_number'] ?? null) : null,
                'payment_terms_days' => $isFreelance ? ($data['payment_terms_days'] ?? 30) : null,
                'languages' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['languages'] ?? 'English'))))),
            ]);

            if ($canRestricted) {
                $person->notes = $data['notes'] ?? null;
            }

            $person->save();

            $person->skills()->sync(Rows::of($data['skills'] ?? [])->mapWithKeys(fn ($s) => [
                (int) $s['skill_id'] => ['level' => (int) $s['level']],
            ])->all());

            if ($canRestricted) {
                $person->restricted()->updateOrCreate([], array_intersect_key($data['restricted'] ?? [], array_flip([
                    'emergency_contact', 'passport_number', 'passport_expiry', 'nationality', 'dietary_requirements', 'access_needs',
                ])));
            } elseif ($creating) {
                $person->restricted()->create([]);
            }

            if ($creating && $isFreelance && ! empty($data['rate']['amount'])) {
                $person->dayRates()->create([
                    'role_name' => $data['rate']['role_name'] ?: $data['job_title'],
                    'amount_minor' => Money::parse((string) $data['rate']['amount'])->minor,
                    'currency' => 'GBP',
                    'effective_from' => $data['rate']['effective_from'] ?: $data['start_date'],
                ]);
            }

            activity('people')
                ->causedBy($user)
                ->performedOn($person)
                ->withProperties(['ref' => 'P-'.$person->id, 'attributes' => ['skills' => count($data['skills'] ?? []), 'restricted_updated' => $canRestricted]])
                ->event($creating ? 'person-created' : 'person-updated')
                ->log(($creating ? 'Added ' : 'Updated ').$person->displayName());

            return $person->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Person $person): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'preferred_name' => ['nullable', 'string', 'max:80'],
            'pronouns' => ['nullable', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255', Rule::unique('people', 'email')->ignore($person?->id)->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:40'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'job_title' => ['required', 'string', 'max:120'],
            'line_manager_id' => ['nullable', 'integer', 'exists:people,id', Rule::notIn(array_filter([$person?->id]))],
            'location_city' => ['required', 'string', 'max:80'],
            'location_country' => ['required', 'string', 'max:80'],
            'bank_holiday_region' => ['nullable', Rule::in(array_keys(BankHoliday::REGIONS))],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'company_name' => ['nullable', 'string', 'max:120'],
            'vat_registered' => ['boolean'],
            'vat_number' => ['nullable', 'required_if:vat_registered,true', 'string', 'max:20'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:120'],
            'languages' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'skills' => ['array', 'max:20'],
            'skills.*.skill_id' => ['required', 'integer', 'distinct', 'exists:skills,id'],
            'skills.*.level' => ['required', 'integer', 'between:1,5'],
            'restricted' => ['array'],
            'restricted.emergency_contact' => ['nullable', 'string', 'max:200'],
            'restricted.passport_number' => ['nullable', 'string', 'max:30'],
            'restricted.passport_expiry' => ['nullable', 'date'],
            'restricted.nationality' => ['nullable', 'string', 'max:60'],
            'restricted.dietary_requirements' => ['nullable', 'string', 'max:200'],
            'restricted.access_needs' => ['nullable', 'string', 'max:200'],
            'rate' => ['array'],
            'rate.role_name' => ['nullable', 'string', 'max:120'],
            'rate.amount' => ['nullable', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'rate.effective_from' => ['nullable', 'date'],
        ];
    }
}
