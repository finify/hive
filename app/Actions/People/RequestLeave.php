<?php

namespace App\Actions\People;

use App\Demo\Rows;
use App\Demo\Workspace;
use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Basic leave for phase 3: an employee asks for days off, their line manager
 * (or HR) decides. Days count weekdays that aren't bank holidays. Balances,
 * half days and the full leave module arrive in phase 5.
 */
class RequestLeave
{
    public function __construct(private readonly Workspace $workspace) {}

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

        $days = $this->workingDays(DateRange::of($data['start_date'], $data['end_date']));

        if ($days === 0) {
            throw ValidationException::withMessages(['end_date' => 'Those dates are all weekends or bank holidays.']);
        }

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

        $this->workspace->forget();
        $dates = DateRange::of($data['start_date'], $data['end_date'])->format();
        $name = $this->workspace->personName($user->person_id);

        activity('people')
            ->causedBy($user)
            ->performedOn($leave)
            ->withProperties(['ref' => 'LR-'.$leave->id, 'attributes' => ['dates' => $dates, 'days' => $days, 'status' => LeaveStatus::Pending->value]])
            ->event('leave-requested')
            ->log("Requested {$days} days’ leave, {$dates}");

        $approver = $this->workspace->person($user->person_id)['line_manager_id'] ?? null;
        $this->workspace->notify($approver, 'leave', "{$name} asked for leave", "{$dates}, {$days} days.", 'approvals');

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
        $this->workspace->forget();

        activity('people')
            ->causedBy($user)
            ->performedOn($leave)
            ->withProperties(['ref' => 'LR-'.$leave->id, 'old' => ['status' => $old->value], 'attributes' => ['status' => LeaveStatus::Cancelled->value]])
            ->event('leave-cancelled')
            ->log('Cancelled leave, '.DateRange::of($leave->start_date->toDateString(), $leave->end_date->toDateString())->format());
    }

    public function workingDays(DateRange $range): int
    {
        $holidays = collect($this->workspace->data->raw('bank_holidays'))->pluck('date')->all();

        return count(array_filter($range->dates(), fn (string $d) => CarbonImmutable::parse($d)->isWeekday() && ! in_array($d, $holidays, true)));
    }
}
