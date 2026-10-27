<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\TouchesWorkspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Spend agreed with a supplier against a budget line. Approved, it adds to
 * committed spend; invoices move that to actual.
 *
 * @property int $id
 * @property string $code
 * @property int $event_id
 * @property int $supplier_id
 * @property int|null $budget_line_id
 * @property PurchaseOrderStatus $status
 * @property string $currency
 * @property string|null $fx_rate
 * @property int $subtotal_minor
 * @property int $vat_minor
 * @property int $total_minor
 * @property int|null $raised_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $goods_received_at
 * @property int|null $goods_received_by
 * @property string|null $notes
 * @property-read Collection<int, PurchaseOrderLine> $lines
 */
class PurchaseOrder extends Model
{
    use TouchesWorkspace;

    protected $fillable = [
        'code', 'event_id', 'supplier_id', 'budget_line_id', 'status', 'currency', 'fx_rate', 'subtotal_minor', 'vat_minor', 'total_minor',
        'raised_by', 'approved_by', 'approved_at', 'sent_at', 'goods_received_at', 'goods_received_by', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class, 'subtotal_minor' => 'integer', 'vat_minor' => 'integer', 'total_minor' => 'integer',
            'approved_at' => 'datetime', 'sent_at' => 'datetime', 'goods_received_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'event_id' => $this->event_id, 'supplier_id' => $this->supplier_id,
            'budget_line_id' => $this->budget_line_id, 'status' => $this->status->value, 'currency' => $this->currency, 'fx_rate' => $this->fx_rate,
            'subtotal_minor' => $this->subtotal_minor, 'vat_minor' => $this->vat_minor, 'total_minor' => $this->total_minor,
            'raised_by' => $this->raised_by, 'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->utc()->format('Y-m-d\TH:i:s\Z'), 'sent_at' => $this->sent_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'goods_received' => $this->goods_received_at !== null, 'notes' => $this->notes,
            'lines' => $this->lines->map(fn (PurchaseOrderLine $l) => [
                'description' => $l->description, 'quantity' => (float) $l->quantity == (int) $l->quantity ? (int) $l->quantity : (float) $l->quantity,
                'unit_price_minor' => $l->unit_price_minor, 'vat_rate' => is_numeric($l->vat_rate) ? (int) $l->vat_rate : $l->vat_rate,
            ])->values()->all(),
        ];
    }
}
