<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Models\Concerns\TouchesWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $event_id
 * @property string $title
 * @property int|null $assignee_id
 * @property Carbon|null $due_date
 * @property TaskStatus $status
 * @property int $sort_order
 */
class Task extends Model
{
    use TouchesWorkspace;

    protected $fillable = ['event_id', 'title', 'assignee_id', 'due_date', 'status', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => TaskStatus::class, 'due_date' => 'date'];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
