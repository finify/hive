<?php

namespace App\Actions\Resourcing;

use App\Demo\Rows;
use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Enums\PhaseType;
use App\Models\Person;
use App\Models\User;
use App\Notifications\CallSheetIssued;
use App\Support\DateRange;
use App\Support\Display;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The call sheet for one event on one day: where to go, how to get in, who
 * is called when, and who to ring. A PDF, emailed to the booked crew on
 * request.
 */
class CallSheet
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * Producers and crew managers, and anyone confirmed on the event that day.
     *
     * @param  array<string, mixed>  $event
     */
    public function canView(User $user, array $event, string $date): bool
    {
        if ($user->can('event.manage-crew', [$event])) {
            return true;
        }

        return $this->crewOn($event['id'], $date)->contains('person_id', $user->person_id);
    }

    /**
     * Days the event has anyone booked, for the picker.
     *
     * @return list<string>
     */
    public function days(int $eventId): array
    {
        $days = [];

        foreach ($this->workspace->assignments()->where('event_id', $eventId) as $a) {
            if (AssignmentStatus::from($a['status'])->holdsPerson()) {
                foreach (DateRange::of($a['start_date'], $a['end_date'])->dates() as $d) {
                    $days[$d] = true;
                }
            }
        }

        ksort($days);

        return array_keys($days);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(int $eventId, string $date): array
    {
        $event = $this->workspace->event($eventId);

        if ($event === null || ! in_array($date, $this->days($eventId), true)) {
            throw ValidationException::withMessages(['date' => 'Nobody is booked on that day.']);
        }

        $phases = Rows::of($event['phases'])->filter(fn ($p) => $p['start_date'] <= $date && $date <= $p['end_date'])
            ->map(fn ($p) => PhaseType::from($p['type'])->label())->values()->all();

        $crew = $this->crewOn($eventId, $date)->map(function ($a) {
            $call = $this->workspace->crewCalls()->get($a['crew_call_id']);

            return [
                'name' => $this->workspace->personName($a['person_id']),
                'role' => $call['role_name'] ?? 'Crew',
                'call' => $call['call_time'] ?? '',
                'wrap' => $call['wrap_time'] ?? '',
                'location' => $call['location'] ?? null,
                'status' => AssignmentStatus::from($a['status'])->label(),
            ];
        })->sortBy(fn ($r) => [$r['call'], $r['role'], $r['name']])->values()->all();

        $contacts = Rows::of($event['team'])->map(fn ($m) => [
            'name' => $this->workspace->personName($m['person_id']),
            'role' => $m['role'],
            'phone' => $this->workspace->person($m['person_id'])['phone'] ?? null,
        ])->values()->all();

        return [
            'event' => $event,
            'date' => $date,
            'dateLabel' => Display::date($date, weekday: true),
            'phases' => $phases,
            'venue' => $this->workspace->venues()->get($event['venue_id']),
            'client' => $this->workspace->clients()->get($event['client_id'])['name'] ?? null,
            'crew' => $crew,
            'firstCall' => $crew[0]['call'] ?? null,
            'lastWrap' => collect($crew)->max('wrap'),
            'contacts' => $contacts,
            'issuedAt' => Display::dateTime(now()),
        ];
    }

    public function pdf(int $eventId, string $date): string
    {
        return Pdf::loadView('pdf.call-sheet', $this->data($eventId, $date))->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();
    }

    public function filename(int $eventId, string $date): string
    {
        return ($this->workspace->event($eventId)['code'] ?? 'event').' call sheet '.$date.'.pdf';
    }

    /**
     * Emails the call sheet to everyone booked that day.
     */
    public function send(User $user, int $eventId, string $date): int
    {
        $event = $this->workspace->event($eventId) ?? [];
        Gate::forUser($user)->authorize('event.manage-crew', [$event]);

        $pdf = $this->pdf($eventId, $date);
        $people = Person::query()->whereIn('id', $this->crewOn($eventId, $date)->pluck('person_id')->unique())->get();

        foreach ($people as $person) {
            $person->notify(new CallSheetIssued($event['code'], $event['name'], $date, $pdf, $this->filename($eventId, $date)));
        }

        activity('resourcing')
            ->causedBy($user)
            ->withProperties(['ref' => $event['code'], 'attributes' => ['date' => $date, 'sent_to' => $people->count()]])
            ->event('call-sheet-sent')
            ->log("Sent the call sheet for {$event['code']} on ".CarbonImmutable::parse($date)->format('j M Y')." to {$people->count()} people");

        return $people->count();
    }

    /**
     * Live bookings covering the day.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function crewOn(int $eventId, string $date): Collection
    {
        return Rows::of($this->workspace->assignments()
            ->filter(fn ($a) => $a['event_id'] === $eventId && AssignmentStatus::from($a['status'])->holdsPerson() && $a['start_date'] <= $date && $date <= $a['end_date'])
            ->all());
    }
}
