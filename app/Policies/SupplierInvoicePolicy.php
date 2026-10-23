<?php

namespace App\Policies;

use App\Enums\SupplierInvoiceStatus;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;

class SupplierInvoicePolicy
{
    public function __construct(private readonly ApprovalEngine $approvals) {}

    /**
     * Whoever the approvals engine has the invoice waiting with (see
     * SupplierInvoiceRule), or their delegate.
     *
     * @param  array<string, mixed>  $invoice
     */
    public function approve(User $user, array $invoice): bool
    {
        if (! in_array($invoice['status'], [SupplierInvoiceStatus::Received->value, SupplierInvoiceStatus::Matched->value], true)) {
            return false;
        }

        $model = SupplierInvoice::query()->find($invoice['id']);

        return $model !== null && $this->approvals->decidableBy($user->person_id, $model) !== null;
    }
}
