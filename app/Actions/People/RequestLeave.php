<?php

namespace App\Actions\People;

use App\Demo\Rows;
use App\Demo\Workspace;
use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Leave\LeaveBook;
use App\Support\DateRange;
use App\Support\Display;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An employee asks for days off; their line manager or HR decides, through
 * the approvals engine. Days count weekdays that aren't bank holidays where
 * the person works. Annual leave can't go beyond what is available in its
 * leave year, nor TOIL beyond what has been earned.
 */
class RequestLeave
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ApprovalEngine $approvals,
        private readonly LeaveBook $leave,
    ) {}

    /**
     * @param  array{leave_type?: mixed, start_date?: mixed, end_date?: mixed, reason?: mixed}  $input
     */
    public function handle(User $user, array $input): LeaveRequest
    {
        abort_unless($user->can('leave.request') && $user->person_id !== null, 403);

        $types = Rows::of($this->workspace->organisation()['leave_types'])->pluck('key')->all();

        $data = Validator::make($input, [
            'leave_type' => ['required', Rule::in($types)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $days = $this->leave->workingDays($user->person_id, DateRange::of($data['start_date'], $data['end_date']));

        if ($days === 0) {
            throw ValidationException::withMessages(['end_date' => 'Those dates are all weekends or bank holidays.']);
        }

        $this->checkBalance($user->person_id, $data['leave_type'], $data['start_date'], $days);

        $overlaps = $this->workspace->leaveRequests()->contains(fn ($l) => $l['person_id'] === $user->person_id
            && in_array($l['status'], [LeaveStatus::Pending->value, LeaveStatus::Approved->value], true)
            && $l['start_date'] <= $data['end_date'] && $data['start_date'] <= $l['end_date']);

        if ($overlaps) {
            throw ValidationException::withMessages(['start_date' => 'You already have leave requested for some of those days.']);
        }

        $leave = LeaveRequest::query()->create([
            'person_id' => $user->person_id, 'leave_type' => $data['leave_type'], 'start_date' => $data['start_date'],
            'end_date' => $data['end_date'], 'days' => $days, 'reason' => $data['reason'] ?? null,
            'status' => LeaveStatus::Pending, 'requested_at' => now(),
        ]);

        $approvers = $this->approvals->request($leave)->pluck('approver_id')->unique();
        $this->workspace->forget();
        $dates = DateRange::of($data['start_date'], $data['end_date'])->format();
        $name = $this->workspace->personName($user->person_id);

        activity('people')
            ->causedBy($user)
            ->performedOn($leave)
            ->withProperties(['ref' => 'LR-'.$leave->id, 'attributes' => ['dates' => $dates, 'days' => $days, 'status' => LeaveStatus::Pending->value]])
            ->event('leave-requested')
            ->log("Requested {$days} days’ leave, {$dates}");

        foreach ($approvers as $approver) {
            $this->workspace->notify($approver, 'leave', "{$name} asked for leave", "{$dates}, {$days} days.", 'approvals');
        }

        return $leave;
    }

    public function cancel(User $user, int $leaveId): void
    {
        $leave = LeaveRequest::query()->whereKey($leaveId)->where('person_id', $user->person_id)->firstOrFail();

        if (! in_array($leave->status, [LeaveStatus::Pending, LeaveStatus::Approved], true) || $leave->start_date->lessThanOrEqualTo($this->workspace->today())) {
            throw ValidationException::withMessages(['leave' => 'Only leave that hasn’t started can be cancelled.']);
        }

        $old = $leave->status;
        $leave->update(['status' => LeaveStatus::Cancelled]);
        $this->approvals->withdraw($leave);
        $this->workspace->forget();

        activity('people')
            ->causedBy($user)
            ->performedOn($leave)
            ->withProperties(['ref' => 'LR-'.$leave->id, 'old' => ['status' => $old->value], 'attributes' => ['status' => LeaveStatus::Cancelled->value]])
            ->event('leave-cancelled')
            ->log('Cancelled leave, '.DateRange::of($leave->start_date->toDateString(), $leave->end_date->toDateString())->format());
    }

    /**
     * Annual leave against its leave year's allowance; TOIL against what has
     * been earned. Other types have no balance.
     */
    private function checkBalance(int $personId, string $type, string $start, int $days): void
    {
        if ($type === 'annual') {
            $balance = $this->leave->balance($personId, $this->leave->yearOf($start));

            if ($days > $balance->available()) {
                throw ValidationException::withMessages(['end_date' => sprintf(
                    'That’s %s, and you have %s of annual leave left to ask for in %d.',
                    Display::days($days), Display::days(max(0, $balance->available())), $balance->year,
                )]);
            }
        }

        if ($type === 'toil') {
            $balance = $this->leave->balance($personId);

            if ($days > $balance->toilAvailable()) {
                throw ValidationException::withMessages(['end_date' => sprintf(
                    'That’s %s, and you have %s of time off in lieu to take.',
                    Display::days($days), Display::days(max(0, $balance->toilAvailable())),
                )]);
            }
        }
    }
}
