<?php

namespace App\Services\AskHive\Operations;

use App\Actions\Resourcing\ReleaseAssignment as Action;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Validation\ValidationException;

class ReleaseBooking extends Support
{
    public static function name(): string
    {
        return 'release_booking';
    }

    public function description(): string
    {
        return 'Release someone from a booking (assignment id from get_event_crew). They are told.';
    }

    public function schema(): array
    {
        return self::object(['assignment_id' => ['type' => 'integer']], ['assignment_id']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('crew.manage');
    }

    public function summary(User $user, array $input): string
    {
        $a = $this->workspace->assignment((int) $this->int($input, 'assignment_id')) ?? throw ValidationException::withMessages(['assignment_id' => 'That booking doesn’t exist.']);
        $event = $this->workspace->event($a['event_id']);

        return 'Release '.$this->workspace->personName($a['person_id'])." from {$event['code']}, ".DateRange::of($a['start_date'], $a['end_date'])->format();
    }

    public function perform(User $user, array $input): string
    {
        app(Action::class)->handle($user, (int) $this->int($input, 'assignment_id'));

        return 'Released. They have been told.';
    }
}
