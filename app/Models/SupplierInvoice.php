<?php

namespace App\Models;

use App\Enums\SupplierInvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A supplier's bill, checked against its PO and goods received.
 *
 * @property int $id
 * @property int $supplier_id
 * @property int|null $purchase_order_id
 * @property int $event_id
 * @property int|null $budget_line_id
 * @property string $invoice_number
 * @property Carbon $invoice_date
 * @property Carbon|null $due_date
 * @property string|null $accounting_id
 * @property Carbon|null $accounting_synced_at
 * @property-read Supplier|null $supplier
 * @property-read PurchaseOrder|null $purchaseOrder
 * @property string $currency
 * @property string|null $fx_rate
 * @property int $net_minor
 * @property int $vat_minor
 * @property int $gross_minor
 * @property SupplierInvoiceStatus $status
 * @property int|null $variance_minor
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $approval_note
 * @property string|null $no_po_reason
 * @property string|null $notes
 * @property string|null $file_path
 * @property string|null $original_name
 * @property Carbon|null $received_at
 * @property Carbon|null $paid_on
 */
class SupplierInvoice extends Model
{
    protected $fillable = [
        'supplier_id', 'purchase_order_id', 'event_id', 'budget_line_id', 'invoice_number', 'invoice_date', 'due_date', 'currency', 'fx_rate', 'net_minor', 'vat_minor',
        'gross_minor', 'status', 'variance_minor', 'approved_by', 'approved_at', 'approval_note', 'no_po_reason', 'notes', 'file_path', 'original_name',
        'received_at', 'paid_on', 'accounting_id', 'accounting_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierInvoiceStatus::class, 'invoice_date' => 'date', 'due_date' => 'date', 'net_minor' => 'integer', 'vat_minor' => 'integer',
            'gross_minor' => 'integer', 'variance_minor' => 'integer', 'approved_at' => 'datetime', 'received_at' => 'datetime', 'paid_on' => 'date',
            'accounting_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id, 'supplier_id' => $this->supplier_id, 'purchase_order_id' => $this->purchase_order_id, 'event_id' => $this->event_id, 'budget_line_id' => $this->budget_line_id,
            'invoice_number' => $this->invoice_number, 'invoice_date' => $this->invoice_date->toDateString(), 'due_date' => $this->due_date?->toDateString(),
            'currency' => $this->currency, 'fx_rate' => $this->fx_rate, 'net_minor' => $this->net_minor, 'vat_minor' => $this->vat_minor,
            'gross_minor' => $this->gross_minor, 'status' => $this->status->value, 'variance_minor' => $this->variance_minor,
            'approved_by' => $this->approved_by, 'approval_note' => $this->approval_note, 'no_po_reason' => $this->no_po_reason, 'notes' => $this->notes,
            'has_file' => $this->file_path !== null, 'received_at' => $this->received_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'paid_on' => $this->paid_on?->toDateString(),
        ];
    }
}
