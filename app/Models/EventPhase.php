<?php

namespace App\Models;

use App\Enums\PhaseType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property PhaseType $type
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string|null $notes
 */
class EventPhase extends Model
{
    protected $fillable = ['event_id', 'type', 'start_date', 'end_date', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => PhaseType::class, 'start_date' => 'date', 'end_date' => 'date'];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
