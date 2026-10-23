<?php

namespace App\Services\Approvals\Rules;

use App\Demo\Workspace;
use App\Models\SupplierInvoice;
use App\Services\Approvals\ApprovalRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Finance or the operations director approves supplier invoices. The
 * event's producer may too, but only for an invoice against a PO and within
 * tolerance; one over tolerance or without a PO needs finance or the
 * operations director.
 */
class SupplierInvoiceRule implements ApprovalRule
{
    use FinanceApprovers;

    public function __construct(private readonly Workspace $workspace) {}

    public function mode(): string
    {
        return self::ANY_OF;
    }

    public function label(): string
    {
        return 'Supplier invoice';
    }

    public function requester(Model $approvable): ?int
    {
        return null;
    }

    public function steps(Model $approvable): array
    {
        /** @var SupplierInvoice $approvable */
        $people = [...$this->financeHolding('supplier_invoices.approve'), ...$this->withRole('operations-director')];
        $invoice = $this->workspace->supplierInvoices()->get($approvable->id);

        if ($approvable->purchase_order_id !== null && $invoice !== null && ! $this->workspace->invoiceOverTolerance($invoice)) {
            $producer = $this->workspace->event($approvable->event_id)['producer_id'] ?? null;
            $people[] = $producer;
        }

        $people = array_values(array_unique(array_filter($people)));

        return $people === [] ? [] : [$people];
    }
}
