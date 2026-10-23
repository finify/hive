<?php

namespace App\Models;

use App\Enums\EventType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A standard task list for an event type, with due dates relative to the
 * event's start.
 *
 * @property int $id
 * @property string $name
 * @property EventType $event_type
 * @property-read Collection<int, TaskTemplateItem> $items
 */
class TaskTemplate extends Model
{
    protected $fillable = ['name', 'event_type'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['event_type' => EventType::class];
    }

    /**
     * @return HasMany<TaskTemplateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TaskTemplateItem::class)->orderBy('sort_order');
    }
}
