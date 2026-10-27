<?php

namespace App\Models;

use App\Models\Concerns\TouchesWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A committed or actual cost on an event, from a PO, an invoice, approved
 * time, an expense or a manual journal. Foreign costs keep the original
 * amount, the rate and the sterling amount (rule 16).
 *
 * @property int $id
 * @property int $event_id
 * @property int|null $budget_line_id
 * @property string $source_type
 * @property int|null $source_id
 * @property string|null $source_ref
 * @property string $kind
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $fx_rate
 * @property int $amount_gbp_minor
 * @property bool $draws_down_remaining
 * @property Carbon $posted_at
 */
class CostEntry extends Model
{
    use TouchesWorkspace;

    protected $fillable = [
        'event_id', 'budget_line_id', 'source_type', 'source_id', 'source_ref', 'kind', 'amount_minor', 'currency',
        'fx_rate', 'amount_gbp_minor', 'draws_down_remaining', 'posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'amount_gbp_minor' => 'integer', 'draws_down_remaining' => 'boolean', 'posted_at' => 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id, 'event_id' => $this->event_id, 'budget_line_id' => $this->budget_line_id,
            'source' => ['type' => $this->source_type, 'id' => $this->source_id, 'ref' => $this->source_ref],
            'kind' => $this->kind, 'amount_minor' => $this->amount_minor, 'currency' => $this->currency, 'fx_rate' => $this->fx_rate,
            'amount_gbp_minor' => $this->amount_gbp_minor, 'draws_down_remaining' => $this->draws_down_remaining,
            'posted_at' => $this->posted_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
