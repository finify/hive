<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property string $description
 * @property string $quantity
 * @property int $unit_price_minor
 * @property string $vat_rate
 */
class PurchaseOrderLine extends Model
{
    protected $fillable = ['purchase_order_id', 'description', 'quantity', 'unit_price_minor', 'vat_rate'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price_minor' => 'integer'];
    }
}
