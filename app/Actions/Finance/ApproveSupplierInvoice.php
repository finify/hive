<?php

namespace App\Actions\Finance;

use App\Demo\Workspace;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Approves a supplier invoice for payment. What it covers of the PO's
 * commitment is released and the invoice becomes Actual, so the forecast
 * moves by exactly the difference (rule 12). An invoice over tolerance, or
 * without a PO, needs a note (rule 11).
 */
class ApproveSupplierInvoice
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ApprovalEngine $approvals,
        private readonly PostCost $costs,
    ) {}

    public function handle(User $approver, int $invoiceId, ?string $note = null): Money
    {
        $invoice = $this->workspace->supplierInvoices()->get($invoiceId);

        if (! $invoice) {
            throw ValidationException::withMessages(['invoice' => 'That invoice no longer exists.']);
        }

        Gate::forUser($approver)->authorize('supplier-invoice.approve', [$invoice]);

        $overTolerance = $this->workspace->invoiceOverTolerance($invoice);

        if ($overTolerance) {
            Validator::make(['note' => $note], ['note' => ['required', 'string', 'min:10', 'max:500']], [
                'note.required' => $invoice['purchase_order_id'] ? 'This invoice is over tolerance. Record why the difference is accepted.' : 'This invoice has no PO. Record why it is accepted.',
                'note.min' => 'Record why the difference is accepted, in a sentence.',
            ])->validate();
        }

        $model = SupplierInvoice::query()->findOrFail($invoiceId);
        $po = $model->purchase_order_id ? PurchaseOrder::query()->find($model->purchase_order_id) : null;

        [$movement, $released, $gbp] = DB::transaction(function () use ($approver, $model, $po, $note) {
            $this->approvals->decide((int) $approver->person_id, $model, true, $note);

            $net = Money::of($model->net_minor, $model->currency);
            $gbp = $this->costs->toGbp($net, $model->fx_rate);
            $released = $po ? $this->costs->release($po, $gbp, $model->invoice_number) : Money::zero();

            $this->costs->actual($model->event_id, $po !== null ? $po->budget_line_id : $model->budget_line_id, 'supplier-invoice', $model->id, $model->invoice_number, $net, $model->fx_rate, false);

            $model->update(['status' => SupplierInvoiceStatus::Approved, 'approved_by' => $approver->person_id, 'approved_at' => now(), 'approval_note' => $note]);

            if ($po) {
                $po->update(['status' => $this->costs->outstanding($po->id)->isPositive() ? PurchaseOrderStatus::PartiallyInvoiced : PurchaseOrderStatus::Invoiced]);
            }

            return [$gbp->minus($released), $released, $gbp];
        });

        $this->workspace->forget();
        $event = $this->workspace->event($invoice['event_id']) ?? [];

        activity('finance')
            ->causedBy($approver)
            ->performedOn($model)
            ->withProperties([
                'ref' => $invoice['invoice_number'],
                'old' => ['status' => $invoice['status'], 'committed' => $released->format()],
                'attributes' => ['status' => SupplierInvoiceStatus::Approved->value, 'actual' => $gbp->format(), 'over_tolerance' => $overTolerance, 'note' => $note],
            ])
            ->event('approved')
            ->log("Approved invoice {$invoice['invoice_number']} on {$event['code']}; forecast moved by {$movement->format()}");

        $this->workspace->notify($event['producer_id'] ?? null, 'invoice', "Invoice {$invoice['invoice_number']} approved", "{$event['code']} forecast moved by {$movement->format()}.", 'events');

        return $movement;
    }
}
