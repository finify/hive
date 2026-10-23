<?php

namespace App\Livewire\People;

use App\Actions\People\SavePerson;
use App\Enums\EmploymentType;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\Department;
use App\Models\Person;
use App\Models\Skill;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Add or edit a person. HR only. Restricted fields appear only to those who
 * may edit them; the starting day rate only when adding a freelancer.
 */
#[Layout('layouts.app')]
class Form extends Component
{
    use UsesWorkspace;

    public ?int $personId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<array{skill_id: int|string, level: int|string}> */
    public array $skills = [];

    /** @var array<string, string|null> */
    public array $restricted = [];

    /** @var array<string, string|null> */
    public array $rate = ['role_name' => '', 'amount' => '', 'effective_from' => ''];

    public function mount(?Person $person = null): void
    {
        if ($person?->exists) {
            $this->authorize('update', $person);
            $person->load(['skills', 'restricted']);
            $this->personId = $person->id;
            $this->form = [
                'first_name' => $person->first_name, 'last_name' => $person->last_name, 'preferred_name' => $person->preferred_name,
                'pronouns' => $person->pronouns, 'email' => $person->email, 'phone' => $person->phone,
                'employment_type' => $person->employment_type->value, 'department_id' => $person->department_id,
                'job_title' => $person->job_title, 'line_manager_id' => $person->line_manager_id,
                'location_city' => $person->location_city, 'location_country' => $person->location_country,
                'start_date' => $person->start_date->toDateString(), 'end_date' => $person->end_date?->toDateString(),
                'company_name' => $person->company_name, 'vat_registered' => $person->vat_registered, 'vat_number' => $person->vat_number,
                'payment_terms_days' => $person->payment_terms_days, 'languages' => implode(', ', $person->languages ?? []),
                'notes' => auth()->user()->can('editRestricted', $person) ? $person->notes : null,
            ];
            $this->skills = $person->skills->map(fn (Skill $s) => ['skill_id' => $s->id, 'level' => (int) $s->getRelationValue('pivot')->getAttribute('level')])->values()->all();

            if (auth()->user()->can('editRestricted', $person)) {
                $r = $person->restricted;
                $this->restricted = [
                    'emergency_contact' => $r?->emergency_contact, 'passport_number' => $r?->passport_number,
                    'passport_expiry' => $r?->passport_expiry?->toDateString(), 'nationality' => $r?->nationality,
                    'dietary_requirements' => $r?->dietary_requirements, 'access_needs' => $r?->access_needs,
                ];
            }

            return;
        }

        $this->authorize('create', Person::class);
        $this->form = [
            'first_name' => '', 'last_name' => '', 'preferred_name' => '', 'pronouns' => '', 'email' => '', 'phone' => '',
            'employment_type' => request('type') === 'freelancer' ? 'freelancer' : 'employee', 'department_id' => '',
            'job_title' => '', 'line_manager_id' => '', 'location_city' => 'London', 'location_country' => 'United Kingdom',
            'start_date' => $this->workspace()->today()->toDateString(), 'end_date' => '', 'company_name' => '',
            'vat_registered' => false, 'vat_number' => '', 'payment_terms_days' => 30, 'languages' => 'English', 'notes' => '',
        ];
        $this->restricted = array_fill_keys(['emergency_contact', 'passport_number', 'passport_expiry', 'nationality', 'dietary_requirements', 'access_needs'], '');
    }

    public function addSkill(): void
    {
        $this->skills[] = ['skill_id' => '', 'level' => 3];
    }

    public function removeSkill(int $index): void
    {
        unset($this->skills[$index]);
        $this->skills = array_values($this->skills);
    }

    public function save(SavePerson $save): void
    {
        $this->resetValidation();
        $person = $this->personId ? Person::query()->findOrFail($this->personId) : null;

        $input = $this->form + [
            'skills' => $this->skills,
            'restricted' => array_map(fn ($v) => $v === '' ? null : $v, $this->restricted),
            'rate' => $this->rate,
        ];

        foreach (['department_id', 'line_manager_id', 'end_date', 'payment_terms_days'] as $nullable) {
            if (($input[$nullable] ?? null) === '') {
                $input[$nullable] = null;
            }
        }

        try {
            $saved = $save->handle(auth()->user(), $input, $person);
        } catch (ValidationException $e) {
            $this->setErrorBag($this->prefixErrors($e->errors()));

            return;
        }

        session()->flash('toast', ['message' => ($person ? 'Saved changes to ' : 'Added ').$saved->displayName().'.']);
        $this->redirectRoute('people.show', $saved->id, navigate: true);
    }

    /**
     * Errors from the Action use the input keys; the form's fields live under
     * `form.` (skills, restricted and rate keep their own names).
     *
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, array<int, string>>
     */
    private function prefixErrors(array $errors): array
    {
        $out = [];

        foreach ($errors as $key => $messages) {
            $root = explode('.', $key)[0];
            $out[in_array($root, ['skills', 'restricted', 'rate'], true) ? $key : 'form.'.$key] = $messages;
        }

        return $out;
    }

    public function render(): View
    {
        $person = $this->personId ? Person::query()->find($this->personId) : null;
        $isFreelance = ($this->form['employment_type'] ?? '') === EmploymentType::Freelancer->value;

        return view('livewire.people.form', [
            'person' => $person,
            'isFreelance' => $isFreelance,
            'types' => EmploymentType::options(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'managers' => Person::query()->active()->where('employment_type', '!=', EmploymentType::Freelancer)->whereKeyNot($this->personId ?? 0)->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'preferred_name']),
            'skillOptions' => Skill::query()->orderBy('name')->get(['id', 'name']),
            'canRestricted' => $person ? auth()->user()->can('editRestricted', $person) : auth()->user()->can('people.view_restricted'),
        ])->title($person ? 'Edit '.$person->displayName() : 'Add a person');
    }
}
