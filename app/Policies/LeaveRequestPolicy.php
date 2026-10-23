<?php

namespace App\Policies;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;

class LeaveRequestPolicy
{
    public function __construct(private readonly ApprovalEngine $approvals) {}

    /**
     * The line manager or HR (see LeaveRequestRule), or their delegate.
     * Nobody approves their own.
     *
     * @param  array<string, mixed>  $leave
     */
    public function approve(User $user, array $leave): bool
    {
        if ($leave['status'] !== LeaveStatus::Pending->value || $leave['person_id'] === $user->person_id) {
            return false;
        }

        $model = LeaveRequest::query()->find($leave['id']);

        return $model !== null && $this->approvals->decidableBy($user->person_id, $model) !== null;
    }
}
