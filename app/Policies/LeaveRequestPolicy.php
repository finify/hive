<?php

namespace App\Policies;

use App\Demo\Workspace;
use App\Enums\LeaveStatus;
use App\Models\User;

class LeaveRequestPolicy
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * HR approves anyone's leave; a line manager approves their reports'.
     * Nobody approves their own.
     *
     * @param  array<string, mixed>  $leave
     */
    public function approve(User $user, array $leave): bool
    {
        if (! $user->can('leave.approve') || $leave['status'] !== LeaveStatus::Pending->value) {
            return false;
        }

        if ($leave['person_id'] === $user->person_id) {
            return false;
        }

        $manager = $this->workspace->person($leave['person_id'])['line_manager_id'] ?? null;

        return $user->can('leave.manage') || ($manager !== null && $manager === $user->person_id);
    }
}
