<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone's approvals handed to a colleague for some dates, for example
 * while they are on leave.
 *
 * @property int $id
 * @property int $person_id
 * @property int $delegate_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $reason
 * @property Carbon|null $cancelled_at
 */
class ApprovalDelegation extends Model
{
    protected $fillable = ['person_id', 'delegate_id', 'starts_on', 'ends_on', 'reason', 'cancelled_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'cancelled_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'delegate_id');
    }

    /**
     * Delegations in force on a date.
     *
     * @param  Builder<ApprovalDelegation>  $query
     */
    public function scopeActiveOn(Builder $query, string $date): void
    {
        $query->whereNull('cancelled_at')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date);
    }
}
