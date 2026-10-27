<?php

namespace App\Services\AskHive\Operations;

use App\Actions\Resourcing\BookPerson as Action;
use App\Enums\AssignmentStatus;
use App\Models\CrewCall;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Validation\ValidationException;

class BookPerson extends Support
{
    public static function name(): string
    {
        return 'book_person';
    }

    public function description(): string
    {
        return 'Book someone onto a crew call (id from list_crew_calls; person id from find_available_people). Freelancers get a request to accept. Dates default to the call’s. A clash needs a reason.';
    }

    public function schema(): array
    {
        return self::object([
            'crew_call_id' => ['type' => 'integer'], 'person_id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => ['second-hold', 'first-hold', 'confirmed']],
            'start_date' => ['type' => 'string'], 'end_date' => ['type' => 'string'], 'clash_reason' => ['type' => 'string'],
        ], ['crew_call_id', 'person_id', 'status']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('crew.manage');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function call(array $input): CrewCall
    {
        return CrewCall::query()->find((int) $this->int($input, 'crew_call_id')) ?? throw ValidationException::withMessages(['crew_call_id' => 'That crew call doesn’t exist.']);
    }

    public function summary(User $user, array $input): string
    {
        $call = $this->call($input);
        $event = $this->workspace->event($call->event_id);
        $status = AssignmentStatus::tryFrom((string) $this->str($input, 'status'))?->label() ?? 'Book';
        $dates = DateRange::of($this->str($input, 'start_date') ?? $call->start_date->toDateString(), $this->str($input, 'end_date') ?? $call->end_date->toDateString())->format();

        return "{$status}: ".$this->workspace->personName((int) $this->int($input, 'person_id'))." as {$call->role_name} on {$event['code']}, {$dates}";
    }

    public function perform(User $user, array $input): string
    {
        $call = $this->call($input);
        $assignment = app(Action::class)->handle($user, $call, [
            'person_id' => $this->int($input, 'person_id'),
            'start_date' => $this->str($input, 'start_date') ?? $call->start_date->toDateString(),
            'end_date' => $this->str($input, 'end_date') ?? $call->end_date->toDateString(),
            'status' => $this->str($input, 'status'),
            'clash_override_reason' => $this->str($input, 'clash_reason'),
        ]);

        return $assignment->responded_at === null ? 'Booking request sent; they’ll accept or decline on My work.' : 'Booked.';
    }
}
