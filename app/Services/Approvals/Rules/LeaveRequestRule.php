<?php

namespace App\Services\Approvals\Rules;

use App\Demo\Workspace;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Leave is decided by the person's line manager or anyone in HR, whoever
 * gets to it first.
 */
class LeaveRequestRule implements ApprovalRule
{
    public function __construct(private readonly Workspace $workspace) {}

    public function mode(): string
    {
        return self::ANY_OF;
    }

    public function label(): string
    {
        return 'Leave';
    }

    public function requester(Model $approvable): ?int
    {
        /** @var LeaveRequest $approvable */
        return $approvable->person_id;
    }

    public function steps(Model $approvable): array
    {
        /** @var LeaveRequest $approvable */
        $manager = $this->workspace->person($approvable->person_id)['line_manager_id'] ?? null;
        $hr = User::query()->active()->permission('leave.manage')->whereNotNull('person_id')->pluck('person_id')->map(fn ($id) => (int) $id)->all();

        $people = array_values(array_unique(array_filter([$manager, ...$hr])));

        return $people === [] ? [] : [$people];
    }
}
