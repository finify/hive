<?php

namespace App\Services\Approvals;

use App\Demo\Workspace;
use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\ApprovalDelegation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The shared approvals engine (section 5.9): one approval row per approver
 * per step, decided by the approver or whoever they have delegated to.
 * Nobody decides on their own submission (rule 5).
 */
class ApprovalEngine
{
    public function __construct(private readonly Workspace $workspace) {}

    public function rule(Model|string $approvable): ApprovalRule
    {
        $class = is_string($approvable) ? $approvable : $approvable::class;
        $rule = config('hive.approvals.rules')[$class] ?? null;

        if ($rule === null) {
            throw new InvalidArgumentException("No approval rule for {$class}.");
        }

        $instance = app($rule);

        if (! $instance instanceof ApprovalRule) {
            throw new InvalidArgumentException("{$rule} is not an approval rule.");
        }

        return $instance;
    }

    /**
     * Opens approvals for something just submitted. Anything still open from
     * an earlier submission is closed first.
     *
     * @return Collection<int, Approval>
     */
    public function request(Model $approvable): Collection
    {
        $rule = $this->rule($approvable);
        $requester = $rule->requester($approvable);
        $steps = array_values(array_filter(array_map(
            fn (array $people) => array_values(array_filter($people, fn (int $id) => $id !== $requester)),
            $rule->steps($approvable),
        )));

        if ($steps === []) {
            throw ValidationException::withMessages(['approval' => 'There is nobody to approve this yet. Ask an administrator to set a line manager.']);
        }

        return DB::transaction(function () use ($approvable, $rule, $requester, $steps) {
            $this->withdraw($approvable);
            $rows = collect();

            foreach ($steps as $index => $people) {
                foreach ($people as $personId) {
                    $rows->push(Approval::query()->create([
                        'approvable_type' => $approvable::class,
                        'approvable_id' => $approvable->getKey(),
                        'requested_by' => $requester,
                        'approver_id' => $personId,
                        'mode' => $rule->mode(),
                        'step' => $index + 1,
                        'status' => $index === 0 ? ApprovalStatus::Pending : ApprovalStatus::Waiting,
                    ]));
                }
            }

            return $rows;
        });
    }

    /**
     * Closes every open approval on something (resubmitted, cancelled or
     * deleted).
     */
    public function withdraw(Model $approvable): void
    {
        $this->open($approvable)->update(['status' => ApprovalStatus::Closed]);
    }

    /**
     * People a person may act for today: themselves and anyone who has
     * delegated to them.
     *
     * @return list<int>
     */
    public function actingFor(int $personId): array
    {
        return array_values(array_unique([
            $personId,
            ...ApprovalDelegation::query()->activeOn($this->workspace->today()->toDateString())
                ->where('delegate_id', $personId)->pluck('person_id')->map(fn ($id) => (int) $id)->all(),
        ]));
    }

    public function canDecide(?int $personId, Approval $approval): bool
    {
        return $personId !== null
            && $approval->status === ApprovalStatus::Pending
            && $approval->requested_by !== $personId
            && in_array($approval->approver_id, $this->actingFor($personId), true);
    }

    /**
     * The approval this person would decide on, if any.
     */
    public function decidableBy(?int $personId, Model $approvable): ?Approval
    {
        if ($personId === null) {
            return null;
        }

        return $this->pendingRows($approvable)->get()->first(fn (Approval $a) => $this->canDecide($personId, $a));
    }

    /**
     * Records a decision. Returns Approved once the last approval needed is
     * in, Rejected on any rejection, Pending while other steps remain.
     */
    public function decide(int $personId, Model $approvable, bool $approve, ?string $comment = null): ApprovalStatus
    {
        $approval = $this->decidableBy($personId, $approvable);

        if ($approval === null) {
            throw new AuthorizationException('You can’t decide on this.');
        }

        return DB::transaction(function () use ($approval, $approvable, $personId, $approve, $comment) {
            $approval->update([
                'status' => $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                'decided_by' => $personId,
                'comment' => $comment,
                'decided_at' => now(),
            ]);

            if (! $approve) {
                $this->withdraw($approvable);

                return ApprovalStatus::Rejected;
            }

            // Any-of: the first decision settles the step.
            $this->pendingRows($approvable)->where('step', $approval->step)->update(['status' => ApprovalStatus::Closed]);

            $next = $this->open($approvable)->where('status', ApprovalStatus::Waiting)->min('step');

            if ($next !== null) {
                $this->open($approvable)->where('step', $next)->update(['status' => ApprovalStatus::Pending]);

                return ApprovalStatus::Pending;
            }

            return ApprovalStatus::Approved;
        });
    }

    /**
     * Who the item is waiting with now.
     *
     * @return list<int>
     */
    public function inboxApproverIds(Model $approvable): array
    {
        return $this->pendingRows($approvable)->pluck('approver_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Everything waiting for a person, directly or by delegation, oldest
     * first.
     *
     * @return Collection<int, Approval>
     */
    public function inbox(int $personId): Collection
    {
        return Approval::query()
            ->where('status', ApprovalStatus::Pending)
            ->whereIn('approver_id', $this->actingFor($personId))
            ->where(fn ($q) => $q->whereNull('requested_by')->orWhere('requested_by', '!=', $personId))
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->unique(fn (Approval $a) => $a->approvable_type.':'.$a->approvable_id)
            ->values()
            ->toBase();
    }

    /**
     * @return Builder<Approval>
     */
    private function open(Model $approvable): Builder
    {
        return Approval::query()
            ->where('approvable_type', $approvable::class)
            ->where('approvable_id', $approvable->getKey())
            ->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Waiting]);
    }

    /**
     * @return Builder<Approval>
     */
    private function pendingRows(Model $approvable): Builder
    {
        return Approval::query()
            ->where('approvable_type', $approvable::class)
            ->where('approvable_id', $approvable->getKey())
            ->where('status', ApprovalStatus::Pending);
    }
}
