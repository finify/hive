<?php

namespace App\Models;

use App\Enums\RevenueType;
use Illuminate\Database\Eloquent\Model;

/**
 * Client revenue on an event: the fee, pass-through costs, a management fee
 * or an agreed change request.
 *
 * @property int $id
 * @property int $event_id
 * @property RevenueType $type
 * @property string $description
 * @property int $amount_minor
 * @property string $currency
 */
class RevenueLine extends Model
{
    protected $fillable = ['event_id', 'type', 'description', 'amount_minor', 'currency'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => RevenueType::class, 'amount_minor' => 'integer'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return ['id' => $this->id, 'event_id' => $this->event_id, 'type' => $this->type->value, 'description' => $this->description, 'amount_minor' => $this->amount_minor, 'currency' => $this->currency];
    }
}
