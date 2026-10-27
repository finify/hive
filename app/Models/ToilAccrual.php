<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time off in lieu earned, usually from approved event time on a weekend or
 * bank holiday. Taken through leave requests of type "toil".
 *
 * @property int $id
 * @property int $person_id
 * @property Carbon $date
 * @property string $days
 * @property string $reason
 * @property int|null $timesheet_entry_id
 * @property int|null $event_id
 * @property int|null $recorded_by
 */
class ToilAccrual extends Model
{
    protected $fillable = ['person_id', 'date', 'days', 'reason', 'timesheet_entry_id', 'event_id', 'recorded_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date', 'days' => 'decimal:1'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
