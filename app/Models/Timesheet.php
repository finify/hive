<?php

namespace App\Models;

use App\Enums\EntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Concerns\TouchesWorkspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person's week, Monday to Sunday.
 *
 * @property int $id
 * @property int $person_id
 * @property Carbon $week_start
 * @property TimesheetStatus $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $locked_at
 * @property-read Collection<int, TimesheetEntry> $entries
 */
class Timesheet extends Model
{
    use TouchesWorkspace;

    protected $fillable = ['person_id', 'week_start', 'status', 'submitted_at', 'locked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['week_start' => 'date', 'status' => TimesheetStatus::class, 'submitted_at' => 'datetime', 'locked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return HasMany<TimesheetEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class)->orderBy('date')->orderBy('start_time')->orderBy('id');
    }

    /**
     * A submitted week's status follows its entries: Returned if any entry
     * is, Approved once every entry is. Draft and Locked are set directly.
     */
    public function syncStatus(): void
    {
        if (in_array($this->status, [TimesheetStatus::Draft, TimesheetStatus::Locked], true)) {
            return;
        }

        $statuses = $this->entries()->pluck('status')->map(fn ($s) => $s instanceof EntryStatus ? $s : EntryStatus::from($s));

        $status = match (true) {
            $statuses->contains(EntryStatus::Returned) => TimesheetStatus::Returned,
            $statuses->isNotEmpty() && $statuses->every(fn ($s) => $s === EntryStatus::Approved) => TimesheetStatus::Approved,
            default => TimesheetStatus::Submitted,
        };

        if ($status !== $this->status) {
            $this->update(['status' => $status]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id,
            'person_id' => $this->person_id,
            'week_start' => $this->week_start->toDateString(),
            'status' => $this->status->value,
            'submitted_at' => $this->submitted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'locked_at' => $this->locked_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'entries' => $this->entries->map(fn (TimesheetEntry $e) => $e->toWorkspaceArray())->values()->all(),
        ];
    }
}
