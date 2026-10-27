<?php

namespace App\Models;

use App\Enums\ChecklistKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A new starter's onboarding or a leaver's offboarding, copied from the
 * template for their employment type when it was started.
 *
 * @property int $id
 * @property int $person_id
 * @property ChecklistKind $kind
 * @property Carbon $anchor_date
 * @property Carbon|null $completed_at
 * @property int|null $started_by
 * @property-read Person $person
 * @property-read Collection<int, ChecklistItem> $items
 */
class Checklist extends Model
{
    protected $fillable = ['person_id', 'kind', 'anchor_date', 'completed_at', 'started_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => ChecklistKind::class, 'anchor_date' => 'date', 'completed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return HasMany<ChecklistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position');
    }

    public function progress(): int
    {
        $total = $this->items->count();

        return $total === 0 ? 100 : (int) round(100 * $this->items->whereNotNull('completed_at')->count() / $total);
    }
}
