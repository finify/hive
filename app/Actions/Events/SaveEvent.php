<?php

namespace App\Actions\Events;

use App\Demo\Rows;
use App\Demo\Workspace;
use App\Enums\EventStage;
use App\Enums\EventType;
use App\Enums\PhaseType;
use App\Models\Event;
use App\Models\EventStageChange;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates an enquiry or updates an event's details, phases and team. The
 * stage is never set here: it moves only through ChangeEventStage, so the
 * stage rules always apply.
 */
class SaveEvent
{
    public const CURRENCIES = ['GBP', 'EUR', 'USD'];

    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input, ?Event $event = null): Event
    {
        if ($event === null) {
            abort_unless($user->can('events.create'), 403);
        } else {
            Gate::forUser($user)->authorize('event.edit', [$this->workspace->event($event->id) ?? []]);
        }

        $data = Validator::make($input, $this->rules(), [
            'client_fee.regex' => 'Enter the fee as an amount, for example 85000 or 85000.50.',
            'team.*.person_id.distinct' => 'Each person can only be on the team once.',
            'phases.*.end_date.after_or_equal' => 'A phase must end on or after the day it starts.',
            'colour.regex' => 'Choose a colour as a hex value, for example #5f6b7a.',
        ])->validate();

        $result = DB::transaction(function () use ($user, $data, $event) {
            $creating = $event === null;
            $event ??= new Event(['stage' => EventStage::Enquiry, 'code' => $this->nextCode()]);
            $old = $creating ? [] : $this->snapshot($event);
            $currency = $data['currency'] ?? 'GBP';

            $event->fill([
                'name' => $data['name'],
                'client_id' => $data['client_id'] ?? null,
                'producer_id' => $data['producer_id'] ?? ($creating ? $user->person_id : null),
                'event_type' => $data['event_type'],
                'venue_id' => $data['venue_id'] ?? null,
                'city' => $data['city'] ?? null,
                'country' => $data['country'] ?? null,
                'timezone' => $data['timezone'] ?? 'Europe/London',
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'expected_attendance' => $data['expected_attendance'] ?? null,
                'client_fee_minor' => filled($data['client_fee'] ?? null) ? Money::parse((string) $data['client_fee'], $currency)->minor : null,
                'currency' => $currency,
                'description' => $data['description'] ?? null,
                'colour' => $data['colour'] ?? '#5f6b7a',
            ]);
            $event->save();

            if ($creating) {
                EventStageChange::query()->create([
                    'event_id' => $event->id, 'from_stage' => null, 'to_stage' => EventStage::Enquiry,
                    'changed_by' => $user->person_id, 'changed_at' => now(),
                ]);
            }

            $event->phases()->delete();

            foreach (Rows::of($data['phases'] ?? []) as $phase) {
                $event->phases()->create([
                    'type' => $phase['type'], 'start_date' => $phase['start_date'], 'end_date' => $phase['end_date'], 'notes' => $phase['notes'] ?? null,
                ]);
            }

            $event->team()->delete();

            foreach (Rows::of($data['team'] ?? []) as $member) {
                $event->team()->create(['person_id' => (int) $member['person_id'], 'role' => $member['role']]);
            }

            $event->load(['phases', 'team']);
            $new = $this->snapshot($event);

            activity('events')
                ->causedBy($user)
                ->performedOn($event)
                ->withProperties([
                    'ref' => $event->code,
                    'old' => array_diff_assoc($old, $new),
                    'attributes' => $creating ? $new : array_diff_assoc($new, $old),
                ])
                ->event($creating ? 'event-created' : 'event-updated')
                ->log(($creating ? 'Added the enquiry ' : 'Updated ').$event->name);

            return $event;
        });

        $this->workspace->forget();

        return $result;
    }

    /**
     * EV-, the two-digit year, then the next number for that year: EV-26128.
     */
    public function nextCode(): string
    {
        $prefix = 'EV-'.$this->workspace->today()->format('y');

        $last = Event::withTrashed()->where('code', 'like', $prefix.'%')->pluck('code')
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max();

        return $prefix.str_pad((string) (($last ?? 100) + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Flat values for the activity log's before and after.
     *
     * @return array<string, scalar|null>
     */
    private function snapshot(Event $event): array
    {
        return [
            'name' => $event->name,
            'client_id' => $event->client_id,
            'producer_id' => $event->producer_id,
            'event_type' => $event->event_type->value,
            'venue_id' => $event->venue_id,
            'city' => $event->city,
            'start_date' => $event->start_date?->toDateString(),
            'end_date' => $event->end_date?->toDateString(),
            'expected_attendance' => $event->expected_attendance,
            'client_fee' => $event->client_fee_minor === null ? null : Money::of($event->client_fee_minor, $event->currency)->format(),
            'phases' => $event->phases->map(fn ($p) => "{$p->type->label()} {$p->start_date->toDateString()}–{$p->end_date->toDateString()}")->implode(', '),
            'team' => $event->team->map(fn ($m) => "{$m->person_id}:{$m->role}")->implode(', '),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->withoutTrashed()],
            'producer_id' => ['nullable', 'integer', Rule::exists('people', 'id')->withoutTrashed()],
            'event_type' => ['required', Rule::enum(EventType::class)],
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'timezone' => ['nullable', 'timezone:all'],
            'start_date' => ['nullable', 'date', 'required_with:end_date'],
            'end_date' => ['nullable', 'date', 'required_with:start_date', 'after_or_equal:start_date'],
            'expected_attendance' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'client_fee' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'currency' => ['nullable', Rule::in(self::CURRENCIES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'colour' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'phases' => ['array', 'max:12'],
            'phases.*.type' => ['required', Rule::enum(PhaseType::class)],
            'phases.*.start_date' => ['required', 'date'],
            'phases.*.end_date' => ['required', 'date', 'after_or_equal:phases.*.start_date'],
            'phases.*.notes' => ['nullable', 'string', 'max:255'],
            'team' => ['array', 'max:30'],
            'team.*.person_id' => ['required', 'integer', 'distinct', Rule::exists('people', 'id')->withoutTrashed()],
            'team.*.role' => ['required', 'string', 'max:80'],
        ];
    }
}
