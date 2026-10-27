<?php

namespace App\Models;

use App\Enums\ChecklistOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $checklist_id
 * @property string $title
 * @property ChecklistOwner $owner
 * @property Carbon $due_date
 * @property int $position
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property-read Checklist $checklist
 */
class ChecklistItem extends Model
{
    protected $fillable = ['checklist_id', 'title', 'owner', 'due_date', 'position', 'completed_at', 'completed_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['owner' => ChecklistOwner::class, 'due_date' => 'date', 'completed_at' => 'datetime', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<Checklist, $this>
     */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }
}
