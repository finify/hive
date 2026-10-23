<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A cost line on an event's budget, in a category, optionally with a
 * supplier. Committed and actual amounts come from cost entries.
 *
 * @property int $id
 * @property int $event_id
 * @property string $category
 * @property string $description
 * @property int|null $supplier_id
 * @property int $budget_minor
 * @property string $currency
 * @property string|null $fx_rate
 * @property int $budget_gbp_minor
 * @property int $remaining_gbp_minor
 * @property string|null $notes
 * @property int $sort_order
 */
class BudgetLine extends Model
{
    protected $fillable = ['event_id', 'category', 'description', 'supplier_id', 'budget_minor', 'currency', 'fx_rate', 'budget_gbp_minor', 'remaining_gbp_minor', 'notes', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['budget_minor' => 'integer', 'budget_gbp_minor' => 'integer', 'remaining_gbp_minor' => 'integer'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id, 'category' => $this->category, 'description' => $this->description, 'supplier_id' => $this->supplier_id,
            'budget_minor' => $this->budget_minor, 'currency' => $this->currency, 'fx_rate' => $this->fx_rate,
            'budget_gbp_minor' => $this->budget_gbp_minor, 'remaining_gbp_minor' => $this->remaining_gbp_minor, 'notes' => $this->notes,
        ];
    }
}
