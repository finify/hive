<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person booked against a crew call for some dates.
 *
 * @property int $id
 * @property int $crew_call_id
 * @property int $event_id
 * @property int $person_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property AssignmentStatus $status
 * @property int|null $agreed_rate_minor
 * @property string $currency
 * @property string|null $clash_override_reason
 * @property string|null $clash_note
 * @property Carbon|null $requested_at
 * @property Carbon|null $responded_at
 */
class Assignment extends Model
{
    protected $fillable = [
        'crew_call_id', 'event_id', 'person_id', 'start_date', 'end_date', 'status', 'agreed_rate_minor', 'currency',
        'clash_override_reason', 'clash_note', 'requested_at', 'responded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class, 'start_date' => 'date', 'end_date' => 'date', 'agreed_rate_minor' => 'integer',
            'requested_at' => 'datetime', 'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CrewCall, $this>
     */
    public function crewCall(): BelongsTo
    {
        return $this->belongsTo(CrewCall::class);
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
        return [
            'id' => $this->id, 'crew_call_id' => $this->crew_call_id, 'event_id' => $this->event_id, 'person_id' => $this->person_id,
            'start_date' => $this->start_date->toDateString(), 'end_date' => $this->end_date->toDateString(),
            'status' => $this->status->value, 'agreed_rate_minor' => $this->agreed_rate_minor, 'currency' => $this->currency,
            'clash_override_reason' => $this->clash_override_reason,
            'clash_note' => $this->clash_note,
            'requested_at' => $this->requested_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'responded_at' => $this->responded_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
