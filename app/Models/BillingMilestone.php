<?php

namespace App\Models;

use App\Enums\BillingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A client payment milestone. The invoice is raised in the accounting
 * system; Hive records its reference and when it was paid.
 *
 * @property int $id
 * @property int $event_id
 * @property string $description
 * @property int|null $percentage
 * @property int $amount_minor
 * @property string $currency
 * @property Carbon $due_date
 * @property BillingStatus $status
 * @property string|null $invoice_reference
 * @property Carbon|null $invoiced_on
 * @property Carbon|null $paid_on
 */
class BillingMilestone extends Model
{
    protected $fillable = ['event_id', 'description', 'percentage', 'amount_minor', 'currency', 'due_date', 'status', 'invoice_reference', 'invoiced_on', 'paid_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => BillingStatus::class, 'due_date' => 'date', 'invoiced_on' => 'date', 'paid_on' => 'date', 'amount_minor' => 'integer', 'percentage' => 'integer'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id, 'event_id' => $this->event_id, 'description' => $this->description, 'percentage' => $this->percentage,
            'amount_minor' => $this->amount_minor, 'currency' => $this->currency, 'due_date' => $this->due_date->toDateString(),
            'status' => $this->status->value, 'invoice_reference' => $this->invoice_reference, 'paid_at' => $this->paid_on?->toDateString(),
        ];
    }
}
