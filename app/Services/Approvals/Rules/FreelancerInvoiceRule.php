<?php

namespace App\Services\Approvals\Rules;

use App\Models\FreelancerInvoice;
use App\Services\Approvals\ApprovalRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Finance approves freelancer invoices for payment, having seen how the
 * total compares with approved time.
 */
class FreelancerInvoiceRule implements ApprovalRule
{
    use FinanceApprovers;

    public function mode(): string
    {
        return self::ANY_OF;
    }

    public function label(): string
    {
        return 'Freelancer invoice';
    }

    public function requester(Model $approvable): ?int
    {
        /** @var FreelancerInvoice $approvable */
        return $approvable->person_id;
    }

    public function steps(Model $approvable): array
    {
        $people = $this->financeHolding('freelancer_invoices.approve');

        return $people === [] ? [] : [$people];
    }
}
