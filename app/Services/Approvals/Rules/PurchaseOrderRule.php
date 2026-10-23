<?php

namespace App\Services\Approvals\Rules;

use App\Demo\Workspace;
use App\Models\PurchaseOrder;
use App\Services\Approvals\ApprovalRule;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Approval limits by level (config hive.finance.po_limits): the event's
 * producer up to their limit, the operations director up to theirs, and
 * above that two approvers in turn: the operations director, then the head
 * of finance. A PO goes to the first level whose limit covers it and that
 * has someone other than the person who raised it (rules 5 and 10).
 */
class PurchaseOrderRule implements ApprovalRule
{
    use FinanceApprovers;

    public function __construct(private readonly Workspace $workspace) {}

    public function mode(): string
    {
        return self::SEQUENTIAL;
    }

    public function label(): string
    {
        return 'Purchase order';
    }

    public function requester(Model $approvable): ?int
    {
        /** @var PurchaseOrder $approvable */
        return $approvable->raised_by;
    }

    public function steps(Model $approvable): array
    {
        /** @var PurchaseOrder $approvable */
        $net = $this->netInGbp($approvable);
        $limits = config('hive.finance.po_limits');
        $raisedBy = $approvable->raised_by;
        $others = fn (array $people) => array_values(array_filter($people, fn (int $id) => $id !== $raisedBy));

        $producer = $this->workspace->event($approvable->event_id)['producer_id'] ?? null;
        $directors = $this->withRole('operations-director');

        if ($producer !== null && $net <= $limits['producer'] && $others([$producer]) !== []) {
            return [[$producer]];
        }

        if ($net <= $limits['operations-director'] && $others($directors) !== []) {
            return [$others($directors)];
        }

        $financeHead = $this->workspace->departments()->firstWhere('name', 'Finance')['head_person_id'] ?? null;

        return array_values(array_filter([$others($directors), $others(array_filter([$financeHead]))]));
    }

    public function netInGbp(PurchaseOrder $po): int
    {
        return $po->currency === 'GBP' || $po->fx_rate === null
            ? $po->subtotal_minor
            : Money::of($po->subtotal_minor, $po->currency)->convert($po->fx_rate, 'GBP')->minor;
    }
}
