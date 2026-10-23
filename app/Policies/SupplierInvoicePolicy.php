<?php

namespace App\Policies;

use App\Demo\Workspace;
use App\Enums\SupplierInvoiceStatus;
use App\Models\User;

class SupplierInvoicePolicy
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * Finance and the operations director approve any invoice; producers only
     * on events they produce.
     *
     * @param  array<string, mixed>  $invoice
     */
    public function approve(User $user, array $invoice): bool
    {
        if (! $user->can('supplier_invoices.approve')) {
            return false;
        }

        if (! in_array($invoice['status'], [SupplierInvoiceStatus::Received->value, SupplierInvoiceStatus::Matched->value], true)) {
            return false;
        }

        $event = $this->workspace->event($invoice['event_id']);

        return $user->can('budgets.view_all') || ($event && $event['producer_id'] === $user->person_id);
    }
}
