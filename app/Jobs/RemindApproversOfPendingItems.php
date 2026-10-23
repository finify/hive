<?php

namespace App\Jobs;

use App\Demo\Workspace;
use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Support\Display;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Monday 09:00: reminds approvers of anything waiting longer than the
 * configured number of days (3 by default).
 */
class RemindApproversOfPendingItems implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(Workspace $workspace): void
    {
        $cutoff = now()->subDays((int) config('hive.approvals.remind_after_days', 3));

        $overdue = Approval::query()
            ->where('status', ApprovalStatus::Pending)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->get()
            ->groupBy('approver_id');

        foreach ($overdue as $approverId => $items) {
            $count = $items->unique(fn (Approval $a) => $a->approvable_type.':'.$a->approvable_id)->count();
            $oldest = $items->first()?->created_at;

            $workspace->notify((int) $approverId, 'approval', $count === 1 ? '1 approval is waiting' : "{$count} approvals are waiting",
                'The oldest has waited since '.Display::date($oldest).'.', 'approvals');
        }
    }
}
