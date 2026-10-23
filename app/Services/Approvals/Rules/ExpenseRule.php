<?php

namespace App\Services\Approvals\Rules;

use App\Demo\Workspace;
use App\Models\Expense;
use App\Services\Approvals\ApprovalRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Expenses: the event's producer (or the line manager when there's no event
 * or the producer is claiming), then finance.
 */
class ExpenseRule implements ApprovalRule
{
    use FinanceApprovers;

    public function __construct(private readonly Workspace $workspace) {}

    public function mode(): string
    {
        return self::SEQUENTIAL;
    }

    public function label(): string
    {
        return 'Expense';
    }

    public function requester(Model $approvable): ?int
    {
        /** @var Expense $approvable */
        return $approvable->person_id;
    }

    public function steps(Model $approvable): array
    {
        /** @var Expense $approvable */
        $producer = $approvable->event_id ? ($this->workspace->event($approvable->event_id)['producer_id'] ?? null) : null;
        $first = $producer !== null && $producer !== $approvable->person_id
            ? $producer
            : ($this->workspace->person($approvable->person_id)['line_manager_id'] ?? null);

        return array_values(array_filter([array_filter([$first]), $this->financeHolding('expenses.approve')]));
    }
}
