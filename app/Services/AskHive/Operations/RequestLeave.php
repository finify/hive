<?php

namespace App\Services\AskHive\Operations;

use App\Actions\People\RequestLeave as Action;
use App\Demo\Rows;
use App\Models\User;
use App\Services\Leave\LeaveBook;
use App\Support\DateRange;
use App\Support\Display;

class RequestLeave extends Support
{
    public static function name(): string
    {
        return 'request_leave';
    }

    public function description(): string
    {
        return 'Ask for leave for the user: annual, toil, training, unpaid, compassionate, parental or sick. Weekends and bank holidays are not counted.';
    }

    public function schema(): array
    {
        return self::object([
            'leave_type' => ['type' => 'string', 'enum' => ['annual', 'toil', 'training', 'unpaid', 'compassionate', 'parental', 'sick']],
            'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            'reason' => ['type' => 'string', 'description' => 'Optional note for the manager'],
        ], ['leave_type', 'start_date', 'end_date']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('leave.request') && $user->person_id !== null;
    }

    public function summary(User $user, array $input): string
    {
        $type = Rows::of($this->workspace->organisation()['leave_types'])->firstWhere('key', $input['leave_type'] ?? '')['name'] ?? 'Leave';
        $range = DateRange::of((string) $input['start_date'], (string) $input['end_date']);
        $days = app(LeaveBook::class)->workingDays((int) $user->person_id, $range);

        return "Ask for {$type}, {$range->format()} (".Display::days($days).')';
    }

    public function perform(User $user, array $input): string
    {
        $leave = app(Action::class)->handle($user, [
            'leave_type' => $this->str($input, 'leave_type'), 'start_date' => $this->str($input, 'start_date'),
            'end_date' => $this->str($input, 'end_date'), 'reason' => $this->str($input, 'reason'),
        ]);

        return 'Leave requested: '.Display::days((float) $leave->days).'. Your manager has been told.';
    }
}
