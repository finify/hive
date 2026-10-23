<?php

namespace App\Actions\People;

use App\Demo\Workspace;
use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DecideLeaveRequest
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * Bookings the leave would overlap. The approver sees these first.
     *
     * @return list<array<string, mixed>>
     */
    public function clashingBookings(int $leaveId): array
    {
        $leave = $this->workspace->leaveRequests()->get($leaveId);

        if (! $leave) {
            return [];
        }

        $range = DateRange::of($leave['start_date'], $leave['end_date']);

        return $this->workspace->assignments()
            ->filter(fn ($a) => $a['person_id'] === $leave['person_id']
                && ! in_array($a['status'], ['released', 'declined'], true)
                && DateRange::of($a['start_date'], $a['end_date'])->overlaps($range))
            ->values()
            ->all();
    }

    public function handle(User $user, int $leaveId, bool $approve): void
    {
        $leave = $this->workspace->leaveRequests()->get($leaveId);

        if (! $leave) {
            throw ValidationException::withMessages(['leave' => 'That leave request no longer exists.']);
        }

        Gate::forUser($user)->authorize('leave.approve-request', [$leave]);

        $status = $approve ? LeaveStatus::Approved : LeaveStatus::Declined;
        LeaveRequest::query()->whereKey($leaveId)->update(['status' => $status, 'approved_by' => $user->person_id, 'approved_at' => now()]);
        $this->workspace->forget();

        activity('people')
            ->causedBy($user)
            ->withProperties(['ref' => 'LR-'.$leaveId, 'old' => ['status' => $leave['status']], 'attributes' => ['status' => $status->value]])
            ->event($approve ? 'approved' : 'declined')
            ->log(($approve ? 'Approved' : 'Declined').' leave for '.$this->workspace->personName($leave['person_id']));

        $this->workspace->notify($leave['person_id'], 'leave', 'Leave '.mb_strtolower($status->label()), DateRange::of($leave['start_date'], $leave['end_date'])->format(), 'my-work');
    }
}
