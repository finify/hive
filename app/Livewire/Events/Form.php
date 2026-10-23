<?php

namespace App\Livewire\Events;

use App\Actions\Events\SaveEvent;
use App\Enums\EmploymentType;
use App\Enums\EventType;
use App\Enums\PhaseType;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\Client;
use App\Models\Event;
use App\Models\Person;
use App\Models\Venue;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Add an enquiry or edit an event's details, phases and team. The stage is
 * changed on the event page, where the stage rules are shown.
 */
#[Layout('layouts.app')]
class Form extends Component
{
    use UsesWorkspace;

    /** Where the agency's venues are; anything else is typed in. */
    private const TIMEZONES = [
        'United Kingdom' => 'Europe/London', 'Ireland' => 'Europe/Dublin', 'Portugal' => 'Europe/Lisbon',
        'Spain' => 'Europe/Madrid', 'France' => 'Europe/Paris', 'Netherlands' => 'Europe/Amsterdam',
        'Germany' => 'Europe/Berlin', 'Belgium' => 'Europe/Brussels', 'Italy' => 'Europe/Rome',
        'Denmark' => 'Europe/Copenhagen', 'Sweden' => 'Europe/Stockholm', 'United States' => 'America/New_York',
        'United Arab Emirates' => 'Asia/Dubai',
    ];

    public ?int $eventId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<array{type: string, start_date: string|null, end_date: string|null, notes: string|null}> */
    public array $phases = [];

    /** @var list<array{person_id: int|string, role: string}> */
    public array $team = [];

    public function mount(?string $code = null): void
    {
        if ($code !== null) {
            $event = $this->workspace()->eventByCode($code);
            abort_unless($event !== null, 404);
            $this->authorize('event.edit', [$event]);
            $this->eventId = $event['id'];
            $this->form = [
                'name' => $event['name'], 'client_id' => $event['client_id'], 'producer_id' => $event['producer_id'],
                'event_type' => $event['event_type'], 'venue_id' => $event['venue_id'], 'city' => $event['city'],
                'country' => $event['country'], 'timezone' => $event['timezone'], 'start_date' => $event['start_date'],
                'end_date' => $event['end_date'], 'expected_attendance' => $event['expected_attendance'],
                'client_fee' => $event['client_fee_minor'] === null ? '' : number_format($event['client_fee_minor'] / 100, 2, '.', ''),
                'currency' => $event['currency'], 'description' => $event['description'], 'colour' => $event['colour'],
            ];
            $this->phases = array_map(fn (array $p) => ['type' => $p['type'], 'start_date' => $p['start_date'], 'end_date' => $p['end_date'], 'notes' => $p['notes'] ?? null], $event['phases']);
            $this->team = array_map(fn (array $m) => ['person_id' => $m['person_id'], 'role' => $m['role']], $event['team']);

            return;
        }

        abort_unless(auth()->user()->can('events.create'), 403);
        $this->form = [
            'name' => '', 'client_id' => '', 'producer_id' => auth()->user()->person_id, 'event_type' => EventType::Conference->value,
            'venue_id' => '', 'city' => 'London', 'country' => 'United Kingdom', 'timezone' => 'Europe/London',
            'start_date' => '', 'end_date' => '', 'expected_attendance' => '', 'client_fee' => '', 'currency' => 'GBP',
            'description' => '', 'colour' => '#5f6b7a',
        ];
        $this->team = auth()->user()->person_id ? [['person_id' => auth()->user()->person_id, 'role' => 'Producer']] : [];
    }

    public function addPhase(): void
    {
        $last = end($this->phases) ?: null;
        $this->phases[] = ['type' => PhaseType::Build->value, 'start_date' => $last['end_date'] ?? ($this->form['start_date'] ?: null), 'end_date' => $last['end_date'] ?? ($this->form['start_date'] ?: null), 'notes' => null];
    }

    public function removePhase(int $index): void
    {
        unset($this->phases[$index]);
        $this->phases = array_values($this->phases);
    }

    public function addMember(): void
    {
        $this->team[] = ['person_id' => '', 'role' => ''];
    }

    public function removeMember(int $index): void
    {
        unset($this->team[$index]);
        $this->team = array_values($this->team);
    }

    /**
     * Picking a venue fills in its city and country.
     */
    public function updatedFormVenueId(mixed $value): void
    {
        $venue = $value ? Venue::query()->find($value) : null;

        if ($venue) {
            $this->form['city'] = $venue->city;
            $this->form['country'] = $venue->country;
            $this->form['timezone'] = self::TIMEZONES[$venue->country] ?? $this->form['timezone'];
        }
    }

    public function save(SaveEvent $save): void
    {
        $this->resetValidation();
        $event = $this->eventId ? Event::query()->findOrFail($this->eventId) : null;

        $input = array_map(fn ($v) => $v === '' ? null : $v, $this->form) + ['phases' => $this->phases, 'team' => $this->team];

        try {
            $saved = $save->handle(auth()->user(), $input, $event);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => [
                (in_array(explode('.', $k)[0], ['phases', 'team'], true) ? $k : 'form.'.$k) => $m,
            ])->all());

            return;
        }

        session()->flash('toast', ['message' => $event ? "Saved changes to {$saved->name}." : "Added {$saved->code} as an enquiry."]);
        $this->redirectRoute('events.show', $saved->code, navigate: true);
    }

    public function render(): View
    {
        $event = $this->eventId ? $this->workspace()->event($this->eventId) : null;

        return view('livewire.events.form', [
            'event' => $event,
            'types' => collect(EventType::cases())->mapWithKeys(fn (EventType $t) => [$t->value => $t->label()]),
            'phaseTypes' => collect(PhaseType::cases())->mapWithKeys(fn (PhaseType $t) => [$t->value => $t->label()]),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'venues' => Venue::query()->orderBy('name')->get(['id', 'name', 'city']),
            'staff' => Person::query()->active()->where('employment_type', '!=', EmploymentType::Freelancer)->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'preferred_name']),
            'people' => Person::query()->active()->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'preferred_name']),
            'currencies' => SaveEvent::CURRENCIES,
            'nextCode' => $event ? null : app(SaveEvent::class)->nextCode(),
        ])->title($event ? 'Edit '.$event['name'] : 'Add an enquiry');
    }
}
