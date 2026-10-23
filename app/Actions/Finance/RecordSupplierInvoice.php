<?php

namespace App\Actions\Finance;

use App\Actions\People\UploadDocument;
use App\Demo\Workspace;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Enums\VatRate;
use App\Models\BudgetLine;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Finance records a supplier invoice, against a PO or without one (which
 * needs a reason). The three-way check: an invoice is Matched when its PO's
 * goods are received and it is within tolerance of what is left to invoice;
 * otherwise it stays Received with the reason shown.
 */
class RecordSupplierInvoice
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ApprovalEngine $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input): SupplierInvoice
    {
        abort_unless($user->can('supplier_invoices.edit'), 403);

        $data = Validator::make($input, [
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'supplier_id' => ['required_without:purchase_order_id', 'nullable', 'integer', 'exists:suppliers,id'],
            'event_id' => ['required_without:purchase_order_id', 'nullable', 'integer', 'exists:events,id'],
            'budget_line_id' => ['required_without:purchase_order_id', 'nullable', 'integer', 'exists:budget_lines,id'],
            'no_po_reason' => ['required_without:purchase_order_id', 'nullable', 'string', 'min:10', 'max:500'],
            'invoice_number' => ['required', 'string', 'max:50'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'currency' => ['required', Rule::in(config('hive.finance.currencies'))],
            'net' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'vat_rate' => ['required', Rule::enum(VatRate::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.UploadDocument::MAX_KILOBYTES],
        ], [
            'no_po_reason.required_without' => 'An invoice without a PO needs a reason.',
            'supplier_id.required_without' => 'Choose the supplier.',
            'event_id.required_without' => 'Choose the event.',
            'invoice_number.required' => 'Enter the supplier’s invoice number.',
            'budget_line_id.required_without' => 'Choose the budget line it is charged to.',
            'net.regex' => 'Enter the net amount, for example 1250 or 1250.50.',
        ])->validate();

        $po = isset($data['purchase_order_id']) ? PurchaseOrder::query()->findOrFail($data['purchase_order_id']) : null;

        if ($po && in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['purchase_order_id' => "{$po->code} isn’t approved, so it can’t be invoiced against."]);
        }

        if ($po && $po->currency !== $data['currency']) {
            throw ValidationException::withMessages(['currency' => "{$po->code} is in {$po->currency}."]);
        }

        $supplierId = $po->supplier_id ?? (int) $data['supplier_id'];
        $eventId = $po->event_id ?? (int) $data['event_id'];

        if (! $po && BudgetLine::query()->whereKey($data['budget_line_id'])->value('event_id') !== $eventId) {
            throw ValidationException::withMessages(['budget_line_id' => 'Choose a line on that event’s budget.']);
        }

        if (SupplierInvoice::query()->where('supplier_id', $supplierId)->where('invoice_number', $data['invoice_number'])->exists()) {
            throw ValidationException::withMessages(['invoice_number' => 'That supplier’s invoice is already recorded.']);
        }

        $net = Money::parse((string) $data['net'], $data['currency']);
        $vat = VatRate::from($data['vat_rate'])->on($net);
        $balance = $po ? $this->uninvoiced($po) : null;

        $invoice = DB::transaction(function () use ($data, $po, $supplierId, $eventId, $net, $vat, $balance) {
            /** @var UploadedFile|null $file */
            $file = $data['file'] ?? null;

            $invoice = SupplierInvoice::query()->create([
                'supplier_id' => $supplierId, 'purchase_order_id' => $po?->id, 'event_id' => $eventId,
                'budget_line_id' => $po ? $po->budget_line_id : (int) $data['budget_line_id'],
                'invoice_number' => $data['invoice_number'], 'invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'] ?? null,
                'currency' => $data['currency'], 'fx_rate' => $po !== null ? $po->fx_rate : ($data['currency'] === 'GBP' ? null : $this->workspace->exchangeRate($data['currency'])),
                'net_minor' => $net->minor, 'vat_minor' => $vat->minor, 'gross_minor' => $net->plus($vat)->minor,
                'status' => SupplierInvoiceStatus::Received, 'variance_minor' => $balance ? $net->minus($balance)->minor : null,
                'no_po_reason' => $po ? null : $data['no_po_reason'], 'notes' => $data['notes'] ?? null, 'received_at' => now(),
                'file_path' => $file?->store("documents/invoices/{$eventId}", config('filesystems.documents_disk', 'local')),
                'original_name' => $file?->getClientOriginalName(),
            ]);

            $this->workspace->forget();
            $this->match($invoice);
            $this->approvals->request($invoice);

            return $invoice;
        });

        $this->workspace->forget();

        activity('finance')
            ->causedBy($user)
            ->performedOn($invoice)
            ->withProperties(['ref' => $invoice->invoice_number, 'attributes' => ['net' => $net->format(), 'po' => $po?->code, 'status' => $invoice->status->value]])
            ->event('recorded')
            ->log("Recorded invoice {$invoice->invoice_number} for {$net->format()}".($po ? " against {$po->code}" : ' without a PO'));

        return $invoice;
    }

    /**
     * Matched when the goods are in and the amount is within tolerance.
     */
    public function match(SupplierInvoice $invoice): void
    {
        if ($invoice->status !== SupplierInvoiceStatus::Received || $invoice->purchase_order_id === null) {
            return;
        }

        $po = PurchaseOrder::query()->find($invoice->purchase_order_id);
        $row = $this->workspace->supplierInvoices()->get($invoice->id);

        if ($po?->goods_received_at !== null && $row !== null && ! $this->workspace->invoiceOverTolerance($row)) {
            $invoice->update(['status' => SupplierInvoiceStatus::Matched]);
        }
    }

    /**
     * What is left to invoice on a PO: its net less invoices already approved.
     */
    public function uninvoiced(PurchaseOrder $po): Money
    {
        $invoiced = SupplierInvoice::query()->where('purchase_order_id', $po->id)
            ->whereIn('status', [SupplierInvoiceStatus::Approved, SupplierInvoiceStatus::Paid])->sum('net_minor');

        return Money::of($po->subtotal_minor - (int) $invoiced, $po->currency);
    }
}
