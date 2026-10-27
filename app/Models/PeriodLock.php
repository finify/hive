<?php

namespace App\Models;

use App\Models\Concerns\TouchesWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A payroll period finance has closed. Time inside an active lock can't be
 * added, changed or returned until finance unlocks it (rule 9).
 *
 * @property int $id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int|null $locked_by
 * @property Carbon $locked_at
 * @property int|null $unlocked_by
 * @property Carbon|null $unlocked_at
 * @property string|null $reason
 */
class PeriodLock extends Model
{
    use TouchesWorkspace;

    protected $fillable = ['period_start', 'period_end', 'locked_by', 'locked_at', 'unlocked_by', 'unlocked_at', 'reason'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'locked_at' => 'datetime', 'unlocked_at' => 'datetime'];
    }

    /**
     * @param  Builder<PeriodLock>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('unlocked_at');
    }
}
