<?php

namespace App\Actions\Finance;

use App\Demo\Workspace;
use App\Enums\SupplierInvoiceStatus;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Disputes an invoice with the supplier, reopens it, or records it paid.
 */
class SettleSupplierInvoice
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ApprovalEngine $approvals,
        private readonly RecordSupplierInvoice $record,
    ) {}

    public function dispute(User $user, int $invoiceId, string $note): void
    {
        abort_unless($user->can('supplier_invoices.edit') || $user->can('supplier-invoice.approve', [$this->workspace->supplierInvoices()->get($invoiceId) ?? []]), 403);
        Validator::make(['note' => $note], ['note' => ['required', 'string', 'min:10', 'max:500']], ['note.required' => 'Say what is wrong so the supplier can put it right.', 'note.min' => 'Say what is wrong so the supplier can put it right.'])->validate();

        $invoice = $this->open($invoiceId);
        $old = $invoice->status;
        $invoice->update(['status' => SupplierInvoiceStatus::Disputed, 'notes' => trim(($invoice->notes ? $invoice->notes."\n" : '').'Disputed: '.$note)]);
        $this->approvals->withdraw($invoice);
        $this->workspace->forget();

        $this->log($user, $invoice, $old, 'disputed', "Disputed invoice {$invoice->invoice_number}: {$note}");
        $this->workspace->notify($this->workspace->event($invoice->event_id)['producer_id'] ?? null, 'invoice', "Invoice {$invoice->invoice_number} disputed", $note, 'supplier-invoices');
    }

    public function reopen(User $user, int $invoiceId): void
    {
        abort_unless($user->can('supplier_invoices.edit'), 403);
        $invoice = SupplierInvoice::query()->where('status', SupplierInvoiceStatus::Disputed)->findOrFail($invoiceId);

        $invoice->update(['status' => SupplierInvoiceStatus::Received]);
        $this->workspace->forget();
        $this->record->match($invoice);
        $this->approvals->request($invoice);
        $this->workspace->forget();

        $this->log($user, $invoice, SupplierInvoiceStatus::Disputed, 'reopened', "Reopened invoice {$invoice->invoice_number} for approval");
    }

    public function markPaid(User $user, int $invoiceId, string $paidOn): void
    {
        abort_unless($user->can('supplier_invoices.edit'), 403);
        Validator::make(['paid_on' => $paidOn], ['paid_on' => ['required', 'date']])->validate();

        $invoice = SupplierInvoice::query()->where('status', SupplierInvoiceStatus::Approved)->findOrFail($invoiceId);
        $invoice->update(['status' => SupplierInvoiceStatus::Paid, 'paid_on' => $paidOn]);
        $this->workspace->forget();

        $this->log($user, $invoice, SupplierInvoiceStatus::Approved, 'paid', "Recorded invoice {$invoice->invoice_number} as paid");
    }

    private function open(int $invoiceId): SupplierInvoice
    {
        $invoice = SupplierInvoice::query()->findOrFail($invoiceId);

        if (! in_array($invoice->status, [SupplierInvoiceStatus::Received, SupplierInvoiceStatus::Matched], true)) {
            throw ValidationException::withMessages(['note' => 'Only an invoice waiting for approval can be disputed.']);
        }

        return $invoice;
    }

    private function log(User $user, SupplierInvoice $invoice, SupplierInvoiceStatus $old, string $event, string $message): void
    {
        activity('finance')
            ->causedBy($user)
            ->performedOn($invoice)
            ->withProperties(['ref' => $invoice->invoice_number, 'old' => ['status' => $old->value], 'attributes' => ['status' => $invoice->status->value]])
            ->event($event)
            ->log($message);
    }
}
