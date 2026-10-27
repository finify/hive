<?php

namespace App\Services\AskHive\Operations;

use App\Actions\People\RequestLeave as Action;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\DateRange;

class CancelLeave extends Support
{
    public static function name(): string
    {
        return 'cancel_leave';
    }

    public function description(): string
    {
        return 'Cancel one of the user’s own leave requests that hasn’t started (id from list_my_leave).';
    }

    public function schema(): array
    {
        return self::object(['leave_id' => ['type' => 'integer']], ['leave_id']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('leave.request') && $user->person_id !== null;
    }

    public function summary(User $user, array $input): string
    {
        $leave = LeaveRequest::query()->where('person_id', $user->person_id)->findOrFail((int) $this->int($input, 'leave_id'));

        return 'Cancel your leave, '.DateRange::of($leave->start_date->toDateString(), $leave->end_date->toDateString())->format();
    }

    public function perform(User $user, array $input): string
    {
        app(Action::class)->cancel($user, (int) $this->int($input, 'leave_id'));

        return 'Leave cancelled.';
    }
}
