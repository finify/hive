<?php

namespace App\Models;

use App\Enums\BudgetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A version of an event's budget. The approved version is the baseline;
 * its lines are kept as they stood so revisions show variance against it.
 *
 * @property int $id
 * @property int $event_id
 * @property int $version
 * @property BudgetStatus $status
 * @property int $total_gbp_minor
 * @property list<array<string, mixed>>|null $lines_snapshot
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $notes
 */
class Budget extends Model
{
    protected $fillable = ['event_id', 'version', 'status', 'total_gbp_minor', 'lines_snapshot', 'approved_by', 'approved_at', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => BudgetStatus::class, 'lines_snapshot' => 'array', 'total_gbp_minor' => 'integer', 'approved_at' => 'datetime', 'version' => 'integer'];
    }
}
