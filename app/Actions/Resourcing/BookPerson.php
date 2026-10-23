<?php

namespace App\Actions\Resourcing;

use App\Data\Booking;
use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Jobs\DetectClashes;
use App\Models\Assignment;
use App\Models\CrewCall;
use App\Models\User;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Books a person against a crew call. Freelancers get a booking request to
 * accept or decline; employees are booked straight away. A booking that
 * overlaps anything else holding the person needs a reason. Confirmed over
 * confirmed bookings only, the reason is the recorded override (rule 1) and
 * both stand; otherwise the clash stays open on the rota to be resolved. A
 * confirmed booking needs a confirmed event and no approved leave (rules 2
 * and 3). Working time warnings are shown but never block.
 */
class BookPerson
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ConfirmAssignment $confirm,
    ) {}

    /**
     * What booking this person would run into, for the form to show first.
     *
     * @return array{clashes: list<string>, blockers: list<string>, warnings: list<string>, request: bool}
     */
    public function preview(CrewCall $call, int $personId, string $start, string $end, AssignmentStatus $status): array
    {
        $event = $this->workspace->event($call->event_id) ?? [];
        $dates = DateRange::of($start, $end);
        $isFreelancer = $this->workspace->isFreelancer($personId);

        return [
            'clashes' => $this->clashes($personId, $dates),
            'blockers' => $status === AssignmentStatus::Confirmed && ! $isFreelancer ? $this->confirm->blockersFor($event, $personId, $start, $end) : [],
            'warnings' => $this->workspace->shiftWarnings($personId, ['dates' => $dates, 'call_time' => $call->call_time, 'wrap_time' => $call->wrap_time])['new'] ?? [],
            'request' => $isFreelancer,
        ];
    }

    /**
     * @param  array{person_id?: mixed, start_date?: mixed, end_date?: mixed, status?: mixed, clash_override_reason?: mixed}  $input
     */
    public function handle(User $user, CrewCall $call, array $input): Assignment
    {
        $event = $this->workspace->event($call->event_id);
        abort_if($event === null, 404);
        Gate::forUser($user)->authorize('event.manage-crew', [$event]);

        $data = Validator::make($input, [
            'person_id' => ['required', 'integer', Rule::exists('people', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'start_date' => ['required', 'date', 'after_or_equal:'.$call->start_date->toDateString(), 'before_or_equal:'.$call->end_date->toDateString()],
            'end_date' => ['required', 'date', 'after_or_equal:start_date', 'before_or_equal:'.$call->end_date->toDateString()],
            'status' => ['required', Rule::in([AssignmentStatus::SecondHold->value, AssignmentStatus::FirstHold->value, AssignmentStatus::Confirmed->value])],
            'clash_override_reason' => ['nullable', 'string', 'max:500'],
        ], [
            'person_id.required' => 'Choose who to book.',
            'start_date.after_or_equal' => 'Book within the crew call’s dates.',
            'end_date.before_or_equal' => 'Book within the crew call’s dates.',
        ])->validate();

        $personId = (int) $data['person_id'];
        $status = AssignmentStatus::from($data['status']);
        $isFreelancer = $this->workspace->isFreelancer($personId);
        $reason = trim((string) ($data['clash_override_reason'] ?? '')) ?: null;
        $dates = DateRange::of($data['start_date'], $data['end_date']);

        // Freelancers confirm by accepting the request.
        if ($isFreelancer && $status === AssignmentStatus::Confirmed) {
            $status = AssignmentStatus::FirstHold;
        }

        $alreadyOnCall = $this->workspace->assignments()->contains(fn ($a) => $a['crew_call_id'] === $call->id && $a['person_id'] === $personId
            && AssignmentStatus::from($a['status'])->holdsPerson() && DateRange::of($a['start_date'], $a['end_date'])->overlaps($dates));

        if ($alreadyOnCall) {
            throw ValidationException::withMessages(['person_id' => $this->workspace->personName($personId).' is already booked on this crew call for those dates.']);
        }

        if ($status === AssignmentStatus::Confirmed) {
            $blockers = $this->confirm->blockersFor($event, $personId, $data['start_date'], $data['end_date']);

            if ($blockers !== []) {
                throw ValidationException::withMessages(['status' => $blockers]);
            }
        }

        $clashes = $this->clashes($personId, $dates);
        $overlapping = array_filter($this->workspace->bookings(), fn (Booking $b) => $b->personId === $personId && $b->dates->overlaps($dates));
        $accepted = $clashes !== [] && $status === AssignmentStatus::Confirmed
            && array_filter($overlapping, fn (Booking $b) => ! ($b->kind === Booking::ASSIGNMENT && $b->confirmed)) === [];

        if ($clashes !== [] && ($reason === null || mb_strlen($reason) < 10)) {
            throw ValidationException::withMessages(['clash_override_reason' => array_merge(
                ['This overlaps other bookings. Record why both can stand, in a sentence, or choose someone else.'],
                $clashes,
            )]);
        }

        $warnings = $this->workspace->shiftWarnings($personId, ['dates' => $dates, 'call_time' => $call->call_time, 'wrap_time' => $call->wrap_time])['new'] ?? [];
        $rate = $this->workspace->rateBook($personId)->rateOn($dates->start)
            ?? ($call->budgeted_rate_minor ? Money::of($call->budgeted_rate_minor) : null);

        $assignment = DB::transaction(fn () => Assignment::query()->create([
            'crew_call_id' => $call->id,
            'event_id' => $call->event_id,
            'person_id' => $personId,
            'start_date' => $dates->start->toDateString(),
            'end_date' => $dates->end->toDateString(),
            'status' => $status,
            'agreed_rate_minor' => $rate?->minor,
            'currency' => 'GBP',
            'clash_override_reason' => $accepted ? $reason : null,
            'clash_note' => $clashes !== [] && ! $accepted ? $reason : null,
            'requested_at' => now(),
            'responded_at' => $isFreelancer ? null : now(),
        ]));

        $this->workspace->forget();
        $name = $this->workspace->personName($personId);

        activity('resourcing')
            ->causedBy($user)
            ->performedOn($assignment)
            ->withProperties([
                'ref' => $event['code'],
                'attributes' => [
                    'person_id' => $personId, 'role' => $call->role_name, 'dates' => $dates->format(), 'status' => $status->value,
                    'clash_reason' => $clashes !== [] ? $reason : null, 'clash_accepted' => $accepted, 'working_time_warnings' => $warnings,
                ],
            ])
            ->event($isFreelancer ? 'booking-requested' : 'booked')
            ->log(($isFreelancer ? "Sent a booking request to {$name}" : "Booked {$name}")." as {$call->role_name} on {$event['code']}, {$dates->format()}");

        $where = $call->location ?: ($event['city'] ?? '');
        $isFreelancer
            ? $this->workspace->notify($personId, 'booking', "Booking request: {$event['code']}", sprintf(
                '%s, %s, %s, as %s%s. Call %s, wrap %s. Accept or decline in Hive.',
                $event['name'], $dates->format(), $where, $call->role_name, $rate ? ' at '.$rate->format().' a day' : '', $call->call_time, $call->wrap_time,
            ), 'my-work')
            : $this->workspace->notify($personId, 'booking', "Booked on {$event['code']}", "{$event['name']}, {$dates->format()}, {$where}, as {$call->role_name} ({$status->label()}).", 'my-work');

        if ($clashes !== [] && ! $accepted) {
            DetectClashes::dispatch($personId, $dates->start->toDateString(), $dates->end->toDateString(), $user->person_id);
        }

        return $assignment;
    }

    /**
     * What else holds the person on those dates, in words.
     *
     * @return list<string>
     */
    public function clashes(int $personId, DateRange $dates): array
    {
        $out = [];

        foreach ($this->workspace->bookings() as $booking) {
            if ($booking->personId !== $personId || ! $booking->dates->overlaps($dates)) {
                continue;
            }

            $out[] = match ($booking->kind) {
                Booking::LEAVE => 'On leave '.$booking->dates->format().'.',
                Booking::UNAVAILABLE => 'Marked unavailable '.$booking->dates->format().'.',
                default => sprintf('Booked on %s %s (%s).', $booking->label, $booking->dates->format(),
                    mb_strtolower(AssignmentStatus::from($this->workspace->assignment((int) $booking->id)['status'] ?? 'confirmed')->label())),
            };
        }

        return $out;
    }
}
