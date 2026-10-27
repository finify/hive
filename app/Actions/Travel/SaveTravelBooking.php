<?php

namespace App\Actions\Travel;

use App\Actions\Finance\PostCost;
use App\Demo\Rows;
use App\Demo\Workspace;
use App\Enums\TravelKind;
use App\Enums\TravelMode;
use App\Enums\TravelStatus;
use App\Models\CostEntry;
use App\Models\TravelBooking;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Books (records) travel or a hotel for someone on an event, or changes or
 * cancels it. The cost is an actual on the event's Travel or Accommodation
 * line (contingency if the budget has neither); a change posts the
 * difference and a cancellation reverses what was posted.
 */
class SaveTravelBooking
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly PostCost $costs,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, int $eventId, array $input, ?int $bookingId = null): TravelBooking
    {
        $event = $this->workspace->event($eventId) ?? abort(404);
        Gate::forUser($user)->authorize('event.manage-crew', [$event]);

        $booking = $bookingId ? TravelBooking::query()->where('event_id', $eventId)->findOrFail($bookingId) : null;
        $data = $this->validate($input, $eventId);
        $kind = TravelKind::from($data['kind']);
        $amount = Money::parse((string) ($data['cost'] ?: '0'), $data['currency']);

        $fields = [
            'event_id' => $eventId, 'person_id' => (int) $data['person_id'], 'kind' => $kind, 'mode' => $data['mode'],
            'from_place' => $kind === TravelKind::Accommodation ? null : $data['from_place'],
            'to_place' => $kind === TravelKind::Accommodation ? ($data['to_place'] ?? null) : $data['to_place'],
            'depart_at' => $kind === TravelKind::Accommodation ? null : $data['depart_at'],
            'arrive_at' => $kind === TravelKind::Accommodation ? null : ($data['arrive_at'] ?? null),
            'check_in' => $kind === TravelKind::Accommodation ? $data['check_in'] : null,
            'check_out' => $kind === TravelKind::Accommodation ? $data['check_out'] : null,
            'provider' => $data['provider'] ?? null, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
            'cost_minor' => $amount->minor, 'currency' => $amount->currency,
        ];

        $saved = DB::transaction(function () use ($booking, $fields, $user, $amount, $kind) {
            if ($booking) {
                $booking->update($fields);
            } else {
                $booking = TravelBooking::query()->create($fields + ['status' => TravelStatus::Booked, 'booked_by' => $user->person_id]);
            }

            $this->postDifference($booking, $kind, $amount);

            return $booking;
        });

        $this->workspace->forget();
        $name = $this->workspace->personName($saved->person_id);

        activity('events')
            ->causedBy($user)
            ->performedOn($saved)
            ->withProperties(['ref' => $saved->ref(), 'attributes' => ['event' => $event['code'], 'kind' => $kind->value, 'mode' => $saved->mode->value, 'cost' => $amount->format()]])
            ->event($booking ? 'travel-changed' : 'travel-booked')
            ->log(($booking ? 'Changed ' : 'Booked ').mb_strtolower($kind->label())." for {$name} on {$event['code']}");

        $this->workspace->notify($saved->person_id, 'booking', $booking ? 'Travel changed' : 'Travel booked',
            "{$event['code']} {$event['name']}: ".$saved->summary().'.', 'my-work');

        return $saved;
    }

    public function cancel(User $user, int $bookingId): void
    {
        $booking = TravelBooking::query()->findOrFail($bookingId);
        $event = $this->workspace->event($booking->event_id) ?? abort(404);
        Gate::forUser($user)->authorize('event.manage-crew', [$event]);

        if ($booking->status === TravelStatus::Cancelled) {
            return;
        }

        DB::transaction(function () use ($booking) {
            $booking->update(['status' => TravelStatus::Cancelled]);
            $this->postDifference($booking, $booking->kind, Money::zero($booking->currency));
        });
        $this->workspace->forget();

        activity('events')
            ->causedBy($user)
            ->performedOn($booking)
            ->withProperties(['ref' => $booking->ref(), 'old' => ['status' => TravelStatus::Booked->value], 'attributes' => ['status' => TravelStatus::Cancelled->value]])
            ->event('travel-cancelled')
            ->log('Cancelled '.mb_strtolower($booking->kind->label())." for {$this->workspace->personName($booking->person_id)} on {$event['code']}");

        $this->workspace->notify($booking->person_id, 'booking', 'Travel cancelled', "{$event['code']}: ".$booking->summary().'.', 'my-work');
    }

    /**
     * Posts what takes the booking's actual cost from what is already posted
     * to the new amount, in the booking's currency; reverses in the old
     * currency first if that changed.
     */
    private function postDifference(TravelBooking $booking, TravelKind $kind, Money $target): void
    {
        $posted = CostEntry::query()->where('source_type', 'travel')->where('source_id', $booking->id)->get();

        foreach ($posted->groupBy('currency') as $currency => $entries) {
            $net = Money::of((int) $entries->sum('amount_minor'), (string) $currency);

            if ((string) $currency !== $target->currency && ! $net->isZero()) {
                $this->post($booking, $kind, $net->negate());
            }
        }

        $already = Money::of((int) $posted->where('currency', $target->currency)->sum('amount_minor'), $target->currency);
        $difference = $target->minus($already);

        if (! $difference->isZero()) {
            $this->post($booking, $kind, $difference);
        }
    }

    private function post(TravelBooking $booking, TravelKind $kind, Money $amount): void
    {
        $lines = Rows::of($this->workspace->budget($booking->event_id)['lines'] ?? []);
        $line = $lines->firstWhere('category', $kind->costCategory()) ?? $lines->firstWhere('category', 'contingency');

        $this->costs->actual($booking->event_id, $line['id'] ?? null, 'travel', $booking->id, $booking->ref(), $amount, null, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input, int $eventId): array
    {
        $onEvent = $this->workspace->assignments()->where('event_id', $eventId)->filter(fn ($a) => ! in_array($a['status'], ['released', 'declined'], true))->pluck('person_id')
            ->merge(Rows::of($this->workspace->event($eventId)['team'] ?? [])->pluck('person_id'))
            ->push($this->workspace->event($eventId)['producer_id'] ?? null)
            ->filter()->unique()->values()->all();

        $kind = TravelKind::tryFrom((string) ($input['kind'] ?? ''));
        $isStay = $kind === TravelKind::Accommodation;

        return Validator::make($input, [
            'person_id' => ['required', 'integer', Rule::in($onEvent)],
            'kind' => ['required', Rule::enum(TravelKind::class)],
            'mode' => ['required', Rule::in(array_map(fn (TravelMode $m) => $m->value, $kind?->modes() ?? TravelMode::cases()))],
            'from_place' => [$isStay ? 'nullable' : 'required', 'nullable', 'string', 'max:120'],
            'to_place' => [$isStay ? 'nullable' : 'required', 'nullable', 'string', 'max:120'],
            'depart_at' => [$isStay ? 'nullable' : 'required', 'nullable', 'date'],
            'arrive_at' => ['nullable', 'date', 'after:depart_at'],
            'check_in' => [$isStay ? 'required' : 'nullable', 'nullable', 'date'],
            'check_out' => [$isStay ? 'required' : 'nullable', 'nullable', 'date', 'after:check_in'],
            'provider' => [$isStay ? 'required' : 'nullable', 'nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:64'],
            'cost' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'currency' => ['required', Rule::in((array) config('hive.finance.currencies'))],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'person_id.required' => 'Choose who is travelling.',
            'person_id.in' => 'Only people booked on or running this event can have travel here.',
            'mode.in' => 'Choose how they travel.',
            'provider.required' => 'Name the hotel.',
            'check_out.after' => 'Check-out is after check-in.',
            'arrive_at.after' => 'Arrival is after departure.',
            'cost.regex' => 'Enter the cost as an amount, for example 245.50.',
        ])->validate();
    }
}
