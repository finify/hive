<?php

namespace App\Actions\Leave;

use App\Demo\Rows;
use App\Demo\Workspace;
use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\Person;
use App\Models\User;
use App\Services\Leave\LeaveBook;
use App\Support\DateRange;
use App\Support\Display;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * HR or a line manager records an absence that wasn't asked for in advance,
 * usually sickness. It is approved as recorded; the type stays visible only
 * to HR, the line manager and the person (sensitive types show as "Absent").
 */
class RecordAbsence
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly LeaveBook $leave,
    ) {}

    /**
     * @param  array{person_id?: mixed, leave_type?: mixed, start_date?: mixed, end_date?: mixed, reason?: mixed}  $input
     */
    public function handle(User $user, array $input): LeaveRequest
    {
        $types = Rows::of($this->workspace->organisation()['leave_types'])->pluck('key')->all();

        $data = Validator::make($input, [
            'person_id' => ['required', 'integer', 'exists:people,id'],
            'leave_type' => ['required', Rule::in($types)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], ['person_id.required' => 'Choose who was absent.'])->validate();

        $person = Person::query()->findOrFail((int) $data['person_id']);

        if (! $this->mayRecordFor($user, $person)) {
            throw new AuthorizationException('Only HR or the person’s line manager can record an absence.');
        }

        $range = DateRange::of($data['start_date'], $data['end_date']);
        $days = $this->leave->workingDays($person->id, $range);

        if ($days === 0) {
            throw ValidationException::withMessages(['end_date' => 'Those dates are all weekends or bank holidays.']);
        }

        $leave = LeaveRequest::query()->create([
            'person_id' => $person->id, 'leave_type' => $data['leave_type'], 'start_date' => $data['start_date'],
            'end_date' => $data['end_date'], 'days' => $days, 'reason' => $data['reason'] ?? null,
            'status' => LeaveStatus::Approved, 'approved_by' => $user->person_id, 'approved_at' => now(), 'requested_at' => now(),
        ]);
        $this->workspace->forget();

        activity('people')
            ->causedBy($user)
            ->performedOn($leave)
            ->withProperties(['ref' => 'LR-'.$leave->id, 'attributes' => ['dates' => $range->format(), 'days' => $days, 'status' => LeaveStatus::Approved->value]])
            ->event('absence-recorded')
            ->log("Recorded an absence for {$this->workspace->personName($person->id)}, {$range->format()}");

        $this->workspace->notify($person->id, 'leave', 'Absence recorded', $range->format().', '.Display::days($days).'.', 'leave');

        return $leave;
    }

    public function mayRecordFor(User $user, Person $person): bool
    {
        return $user->can('leave.manage')
            || ($user->can('leave.approve') && $user->person_id !== null && $person->line_manager_id === $user->person_id);
    }
}
