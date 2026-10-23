<?php

namespace App\Services\Approvals;

use Illuminate\Database\Eloquent\Model;

/**
 * Who approves one kind of thing, and how. Rules are listed per type in
 * config/hive.php (approvals.rules).
 */
interface ApprovalRule
{
    public const SINGLE = 'single';

    public const SEQUENTIAL = 'sequential';

    public const ANY_OF = 'any-of';

    /**
     * single, sequential or any-of.
     */
    public function mode(): string;

    /**
     * The person who submitted it; they never approve it (rule 5).
     */
    public function requester(Model $approvable): ?int;

    /**
     * Approver person ids by step. Single: one step with one person.
     * Sequential: one person per step, in order. Any-of: one step, anyone
     * in it decides.
     *
     * @return list<list<int>>
     */
    public function steps(Model $approvable): array;

    /**
     * A short label for the inbox, for example "Timesheet entry".
     */
    public function label(): string;
}
