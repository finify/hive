<?php

namespace App\Models;

use App\Enums\EntryStatus;
use App\Models\Concerns\TouchesWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A line of time: a day on an event or an internal code.
 *
 * @property int $id
 * @property int $timesheet_id
 * @property Carbon $date
 * @property int|null $event_id
 * @property string|null $internal_code
 * @property string|null $phase
 * @property string $start_time
 * @property string $end_time
 * @property int $break_minutes
 * @property string $hours
 * @property string $days
 * @property string|null $notes
 * @property EntryStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $return_comment
 * @property-read Timesheet $timesheet
 */
class TimesheetEntry extends Model
{
    use TouchesWorkspace;

    protected $fillable = [
        'timesheet_id', 'date', 'event_id', 'internal_code', 'phase', 'start_time', 'end_time', 'break_minutes',
        'hours', 'days', 'notes', 'status', 'approved_by', 'approved_at', 'return_comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date', 'status' => EntryStatus::class, 'break_minutes' => 'integer',
            'hours' => 'decimal:2', 'days' => 'decimal:1', 'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Timesheet, $this>
     */
    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    /**
     * @return MorphMany<Approval, $this>
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        $hours = (float) $this->hours;
        $days = (float) $this->days;

        return [
            'id' => $this->id, 'date' => $this->date->toDateString(), 'event_id' => $this->event_id, 'internal_code' => $this->internal_code,
            'phase' => $this->phase, 'start_time' => $this->start_time, 'end_time' => $this->end_time, 'break_minutes' => $this->break_minutes,
            'hours' => floor($hours) === $hours ? (int) $hours : $hours, 'days' => floor($days) === $days ? (int) $days : $days,
            'notes' => $this->notes, 'status' => $this->status->value, 'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->utc()->format('Y-m-d\TH:i:s\Z'), 'return_comment' => $this->return_comment,
        ];
    }
}
