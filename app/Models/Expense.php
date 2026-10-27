<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Models\Concerns\TouchesWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Money someone spent on an event, claimed with a receipt (or miles).
 *
 * @property int $id
 * @property int $person_id
 * @property int|null $event_id
 * @property ExpenseCategory $category
 * @property Carbon $date
 * @property string $description
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $fx_rate
 * @property int $amount_gbp_minor
 * @property int $vat_minor
 * @property string|null $mileage_miles
 * @property string|null $receipt_path
 * @property string|null $receipt_name
 * @property ExpenseStatus $status
 * @property string|null $decision_comment
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 */
class Expense extends Model
{
    use TouchesWorkspace;

    protected $fillable = [
        'person_id', 'event_id', 'category', 'date', 'description', 'amount_minor', 'currency', 'fx_rate', 'amount_gbp_minor', 'vat_minor',
        'mileage_miles', 'receipt_path', 'receipt_name', 'status', 'decision_comment', 'submitted_at', 'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class, 'status' => ExpenseStatus::class, 'date' => 'date', 'amount_minor' => 'integer',
            'amount_gbp_minor' => 'integer', 'vat_minor' => 'integer', 'mileage_miles' => 'decimal:1', 'submitted_at' => 'datetime', 'decided_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id, 'person_id' => $this->person_id, 'event_id' => $this->event_id, 'category' => $this->category->value,
            'date' => $this->date->toDateString(), 'description' => $this->description, 'amount_minor' => $this->amount_minor,
            'currency' => $this->currency, 'fx_rate' => $this->fx_rate, 'amount_gbp_minor' => $this->amount_gbp_minor, 'vat_minor' => $this->vat_minor,
            'mileage_miles' => $this->mileage_miles === null ? null : (float) $this->mileage_miles, 'status' => $this->status->value,
            'receipt' => $this->receipt_name, 'has_receipt' => $this->receipt_path !== null, 'decision_comment' => $this->decision_comment,
        ];
    }
}
