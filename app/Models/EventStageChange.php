<?php

namespace App\Models;

use App\Enums\EventStage;
use App\Models\Concerns\TouchesWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property EventStage|null $from_stage
 * @property EventStage $to_stage
 * @property int|null $changed_by
 * @property string|null $note
 * @property Carbon $changed_at
 */
class EventStageChange extends Model
{
    use TouchesWorkspace;

    public $timestamps = false;

    protected $fillable = ['event_id', 'from_stage', 'to_stage', 'changed_by', 'note', 'changed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['from_stage' => EventStage::class, 'to_stage' => EventStage::class, 'changed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
