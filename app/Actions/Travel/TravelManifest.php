<?php

namespace App\Actions\Travel;

use App\Demo\Workspace;
use App\Enums\TravelKind;
use App\Enums\TravelStatus;
use App\Models\PersonRestricted;
use App\Models\TravelBooking;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;

/**
 * The travel manifest for an event: who travels how and when, and where
 * they stay. Passport details and access needs come from the restricted
 * profile only for people with people.view_restricted, and including them
 * is logged. Costs only for people who may see the event's budget.
 */
class TravelManifest
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @return array{filename: string, rows: list<list<mixed>>}
     */
    public function build(User $user, string $code): array
    {
        $event = $this->workspace->eventByCode($code) ?? abort(404);
        Gate::forUser($user)->authorize('event.manage-crew', [$event]);

        $restricted = $user->can('people.view_restricted');
        $costs = $user->can('budgets.view');
        $bookings = TravelBooking::query()->where('event_id', $event['id'])->where('status', TravelStatus::Booked)
            ->orderBy('person_id')->orderByRaw('coalesce(depart_at, check_in)')->get();
        $profiles = $restricted ? PersonRestricted::query()->whereIn('person_id', $bookings->pluck('person_id'))->get()->keyBy('person_id') : collect();

        $header = ['Person', 'What', 'How', 'From', 'To', 'Departs', 'Arrives', 'Hotel or operator', 'Reference', 'Check-in', 'Check-out'];

        if ($restricted) {
            array_push($header, 'Nationality', 'Passport number', 'Passport expiry', 'Access needs');
        }

        if ($costs) {
            array_push($header, 'Cost', 'Currency');
        }

        $rows = [$header];

        foreach ($bookings as $b) {
            $stay = $b->kind === TravelKind::Accommodation;
            $row = [
                $this->workspace->personName($b->person_id), $b->kind->label(), $b->mode->label(),
                $b->from_place, $b->to_place,
                $b->depart_at?->format('Y-m-d H:i'), $b->arrive_at?->format('Y-m-d H:i'),
                $b->provider, $b->reference,
                $stay ? $b->check_in?->toDateString() : null, $stay ? $b->check_out?->toDateString() : null,
            ];

            if ($restricted) {
                $p = $profiles->get($b->person_id);
                array_push($row, $p?->nationality, $p?->passport_number, $p?->passport_expiry?->toDateString(), $p?->access_needs);
            }

            if ($costs) {
                array_push($row, Money::of($b->cost_minor, $b->currency)->format(), $b->currency);
            }

            $rows[] = $row;
        }

        activity('events')
            ->causedBy($user)
            ->withProperties(['ref' => $event['code'], 'attributes' => ['bookings' => $bookings->count(), 'restricted_fields' => $restricted]])
            ->event('travel-manifest-exported')
            ->log("Exported the travel manifest for {$event['code']}".($restricted ? ', with passport details' : ''));

        return ['filename' => "{$event['code']}-travel-manifest-{$this->workspace->today()->toDateString()}.csv", 'rows' => $rows];
    }
}
