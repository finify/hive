<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request for time off. Approved leave makes the person unavailable on
 * the rota and blocks confirmed bookings (rule 2).
 *
 * @property int $id
 * @property int $person_id
 * @property string $leave_type
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $days
 * @property string|null $reason
 * @property LeaveStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $requested_at
 */
class LeaveRequest extends Model
{
    protected $fillable = ['person_id', 'leave_type', 'start_date', 'end_date', 'days', 'reason', 'status', 'approved_by', 'approved_at', 'requested_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeaveStatus::class, 'start_date' => 'date', 'end_date' => 'date', 'days' => 'decimal:1',
            'approved_at' => 'datetime', 'requested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        $days = (float) $this->days;

        return [
            'id' => $this->id, 'person_id' => $this->person_id, 'leave_type' => $this->leave_type,
            'start_date' => $this->start_date->toDateString(), 'end_date' => $this->end_date->toDateString(),
            'days' => floor($days) === $days ? (int) $days : $days, 'status' => $this->status->value,
            'approved_by' => $this->approved_by, 'reason' => $this->reason,
            'requested_at' => $this->requested_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
