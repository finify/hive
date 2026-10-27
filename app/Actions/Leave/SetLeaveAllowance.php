<?php

namespace App\Actions\Leave;

use App\Demo\Workspace;
use App\Models\LeaveAllowance;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * HR sets a person's annual leave allowance and carry-over for a leave year.
 */
class SetLeaveAllowance
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array{days_allowed?: mixed, days_carried_over?: mixed}  $input
     */
    public function handle(User $user, int $personId, int $year, array $input): LeaveAllowance
    {
        Gate::forUser($user)->authorize('leave.manage');

        $data = Validator::make($input, [
            'days_allowed' => ['required', 'numeric', 'min:0', 'max:60', 'multiple_of:0.5'],
            'days_carried_over' => ['required', 'numeric', 'min:0', 'max:'.config('hive.leave.carry_over_max'), 'multiple_of:0.5'],
        ], [
            'days_carried_over.max' => 'No more than :max days can be carried over.',
            'multiple_of' => 'Use whole or half days.',
        ])->validate();

        $row = LeaveAllowance::query()->firstOrNew(['person_id' => $personId, 'leave_year' => $year]);
        $old = $row->exists ? ['days_allowed' => (float) $row->days_allowed, 'days_carried_over' => (float) $row->days_carried_over] : [];
        $row->fill(['days_allowed' => $data['days_allowed'], 'days_carried_over' => $data['days_carried_over']])->save();

        activity('people')
            ->causedBy($user)
            ->performedOn($row)
            ->withProperties(['ref' => "LA-{$personId}-{$year}", 'old' => $old, 'attributes' => ['days_allowed' => (float) $data['days_allowed'], 'days_carried_over' => (float) $data['days_carried_over']]])
            ->event('allowance-set')
            ->log("Set {$this->workspace->personName($personId)}’s {$year} leave allowance");

        return $row;
    }
}
