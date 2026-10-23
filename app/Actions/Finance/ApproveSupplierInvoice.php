<?php

namespace App\Actions\Finance;

use App\Demo\Workspace;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Approves a supplier invoice for payment. The PO's committed amount is
 * released and the invoice amount becomes Actual, so the forecast moves by
 * exactly the variance. An invoice over tolerance needs a note.
 */
class ApproveSupplierInvoice
{
    public function __construct(private readonly Workspace $workspace) {}

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
                'note.required' => 'This invoice is over tolerance. Record why the difference is accepted.',
                'note.min' => 'This invoice is over tolerance. Record why the difference is accepted.',
            ])->validate();
        }

        $po = $this->workspace->purchaseOrders()->get($invoice['purchase_order_id']);
        $fx = $invoice['fx_rate'];
        $gbp = $invoice['currency'] === 'GBP'
            ? Money::of($invoice['net_minor'])
            : Money::of($invoice['net_minor'], $invoice['currency'])->convert((string) $fx, 'GBP');

        $released = $this->workspace->costEntries($invoice['event_id'])
            ->filter(fn ($c) => $c['kind'] === 'committed' && ($c['source']['type'] ?? null) === 'purchase-order' && ($c['source']['id'] ?? null) === $po['id']);

        foreach ($released as $entry) {
            $this->workspace->ledger->put('removed_cost_entries', $entry['id'], true);
        }

        $this->workspace->ledger->push('cost_entries', [
            'id' => $this->workspace->ledger->nextId('cost_entries', 100_000),
            'event_id' => $invoice['event_id'],
            'budget_line_id' => $po['budget_line_id'],
            'source' => ['type' => 'supplier-invoice', 'id' => $invoiceId, 'ref' => $invoice['invoice_number']],
            'kind' => 'actual',
            'amount_minor' => $invoice['net_minor'],
            'currency' => $invoice['currency'],
            'fx_rate' => $fx,
            'amount_gbp_minor' => $gbp->minor,
            'posted_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        $this->workspace->ledger->put('invoices', $invoiceId, ['status' => SupplierInvoiceStatus::Approved->value, 'approved_by' => $approver->person_id, 'approval_note' => $note]);
        $this->workspace->ledger->put('purchase_orders', $po['id'], ['status' => PurchaseOrderStatus::Invoiced->value]);
        $this->workspace->forget();

        $releasedTotal = Money::of((int) $released->sum('amount_gbp_minor'));
        $movement = $gbp->minus($releasedTotal);
        $event = $this->workspace->event($invoice['event_id']);

        activity('finance')
            ->causedBy($approver)
            ->withProperties([
                'ref' => $invoice['invoice_number'],
                'old' => ['status' => $invoice['status'], 'committed' => $releasedTotal->format()],
                'attributes' => ['status' => SupplierInvoiceStatus::Approved->value, 'actual' => $gbp->format(), 'over_tolerance' => $overTolerance, 'note' => $note],
            ])
            ->event('approved')
            ->log("Approved invoice {$invoice['invoice_number']} on {$event['code']}; forecast moved by {$movement->format()}");

        $this->workspace->notify($event['producer_id'], 'invoice', "Invoice {$invoice['invoice_number']} approved", "{$event['code']} forecast moved by {$movement->format()}.", 'events');

        return $movement;
    }
}
