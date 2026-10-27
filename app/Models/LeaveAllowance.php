<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's annual leave for one leave year: the allowance, days carried
 * over from the year before, and days taken before Hive recorded leave.
 *
 * @property int $id
 * @property int $person_id
 * @property int $leave_year
 * @property string $days_allowed
 * @property string $days_carried_over
 * @property string $days_taken_before
 */
class LeaveAllowance extends Model
{
    protected $fillable = ['person_id', 'leave_year', 'days_allowed', 'days_carried_over', 'days_taken_before'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['leave_year' => 'integer', 'days_allowed' => 'decimal:1', 'days_carried_over' => 'decimal:1', 'days_taken_before' => 'decimal:1'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
