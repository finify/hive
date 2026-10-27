<?php

namespace App\Models;

use App\Models\Concerns\TouchesWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A requirement on an event: a role, a quantity and the dates it's needed.
 * Its status (open, partially filled, filled) is worked out from its
 * assignments, so it can never disagree with them.
 *
 * @property int $id
 * @property int $event_id
 * @property string $role_name
 * @property int|null $skill_id
 * @property int|null $min_skill_level
 * @property int $quantity
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string|null $phase
 * @property string $call_time
 * @property string $wrap_time
 * @property string|null $location
 * @property int|null $budgeted_rate_minor
 * @property string $currency
 * @property string|null $notes
 */
class CrewCall extends Model
{
    use TouchesWorkspace;

    protected $fillable = [
        'event_id', 'role_name', 'skill_id', 'min_skill_level', 'quantity', 'start_date', 'end_date', 'phase',
        'call_time', 'wrap_time', 'location', 'budgeted_rate_minor', 'currency', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'quantity' => 'integer', 'min_skill_level' => 'integer', 'budgeted_rate_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id, 'event_id' => $this->event_id, 'role_name' => $this->role_name, 'skill_id' => $this->skill_id,
            'min_skill_level' => $this->min_skill_level, 'quantity' => $this->quantity,
            'start_date' => $this->start_date->toDateString(), 'end_date' => $this->end_date->toDateString(),
            'phase' => $this->phase, 'call_time' => $this->call_time, 'wrap_time' => $this->wrap_time, 'location' => $this->location,
            'budgeted_rate_minor' => $this->budgeted_rate_minor, 'currency' => $this->currency, 'notes' => $this->notes,
        ];
    }
}
