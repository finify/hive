<?php

namespace App\Services\AskHive\Operations;

use App\Actions\People\DecideLeaveRequest as Action;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Validation\ValidationException;

class DecideLeave extends Support
{
    public static function name(): string
    {
        return 'decide_leave';
    }

    public function description(): string
    {
        return 'Approve or decline a leave request waiting for the user (id from list_leave_to_decide). Mention any booking it overlaps.';
    }

    public function schema(): array
    {
        return self::object(['leave_id' => ['type' => 'integer'], 'approve' => ['type' => 'boolean']], ['leave_id', 'approve']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('leave.approve');
    }

    public function summary(User $user, array $input): string
    {
        $leave = $this->workspace->leaveRequests()->get((int) $this->int($input, 'leave_id'))
            ?? throw ValidationException::withMessages(['leave_id' => 'That leave request doesn’t exist.']);

        return (! empty($input['approve']) ? 'Approve' : 'Decline').' '.$this->workspace->personName($leave['person_id']).'’s leave, '
            .DateRange::of($leave['start_date'], $leave['end_date'])->format();
    }

    public function perform(User $user, array $input): string
    {
        $approve = (bool) ($input['approve'] ?? false);
        app(Action::class)->handle($user, (int) $this->int($input, 'leave_id'), $approve);

        return ($approve ? 'Approved' : 'Declined').'. They have been told.';
    }
}
