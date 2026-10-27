<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\DocumentType;
use App\Enums\EventStage;
use App\Enums\EventType;
use App\Models\Concerns\TouchesWorkspace;
use App\Support\Money;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A client project: the record everything else hangs off.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $client_id
 * @property int|null $producer_id
 * @property EventType $event_type
 * @property EventStage $stage
 * @property int|null $venue_id
 * @property string|null $city
 * @property string|null $country
 * @property string $timezone
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property int|null $expected_attendance
 * @property int|null $client_fee_minor
 * @property string $currency
 * @property Money|null $client_fee
 * @property string|null $description
 * @property string $colour
 * @property-read Collection<int, EventPhase> $phases
 * @property-read Collection<int, EventTeamMember> $team
 * @property-read Collection<int, EventStageChange> $stageChanges
 * @property-read Collection<int, Task> $tasks
 * @property-read Collection<int, Document> $documents
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, SoftDeletes, TouchesWorkspace;

    protected $fillable = [
        'code', 'name', 'client_id', 'producer_id', 'event_type', 'stage', 'venue_id', 'city', 'country', 'timezone',
        'start_date', 'end_date', 'expected_attendance', 'client_fee_minor', 'currency', 'description', 'colour',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => EventType::class,
            'stage' => EventStage::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'client_fee_minor' => 'integer',
            'client_fee' => MoneyCast::class.':client_fee_minor,currency',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function producer(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'producer_id');
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * @return HasMany<EventPhase, $this>
     */
    public function phases(): HasMany
    {
        return $this->hasMany(EventPhase::class)->orderBy('start_date')->orderBy('id');
    }

    /**
     * @return HasMany<EventTeamMember, $this>
     */
    public function team(): HasMany
    {
        return $this->hasMany(EventTeamMember::class)->orderBy('id');
    }

    /**
     * @return HasMany<EventStageChange, $this>
     */
    public function stageChanges(): HasMany
    {
        return $this->hasMany(EventStageChange::class)->orderBy('changed_at')->orderBy('id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('sort_order')->orderBy('due_date')->orderBy('id');
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')->orderBy('id');
    }

    public function hasSignedContract(): bool
    {
        return $this->documents->contains(fn (Document $d) => $d->type === DocumentType::Contract);
    }

    /**
     * The shape the rest of Hive reads (see App\Demo\Workspace). Relations
     * must be eager loaded: phases, team, stageChanges, tasks, documents.
     *
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'client_id' => $this->client_id,
            'producer_id' => $this->producer_id,
            'event_type' => $this->event_type->value,
            'stage' => $this->stage->value,
            'venue_id' => $this->venue_id,
            'city' => $this->city,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'expected_attendance' => $this->expected_attendance,
            'client_fee_minor' => $this->client_fee_minor,
            'currency' => $this->currency,
            'colour' => $this->colour,
            'description' => $this->description,
            'phases' => $this->phases->map(fn (EventPhase $p) => [
                'id' => $p->id, 'type' => $p->type->value, 'start_date' => $p->start_date->toDateString(), 'end_date' => $p->end_date->toDateString(), 'notes' => $p->notes,
            ])->values()->all(),
            'team' => $this->team->map(fn (EventTeamMember $m) => ['person_id' => $m->person_id, 'role' => $m->role])->values()->all(),
            'stage_history' => $this->stageChanges->map(fn (EventStageChange $c) => [
                'from' => $c->from_stage?->value, 'to' => $c->to_stage->value, 'by' => $c->changed_by,
                'at' => $c->changed_at->utc()->format('Y-m-d\TH:i:s\Z'), 'note' => $c->note,
            ])->values()->all(),
            'tasks' => $this->tasks->map(fn (Task $t) => [
                'id' => $t->id, 'title' => $t->title, 'assignee_id' => $t->assignee_id, 'due_date' => $t->due_date?->toDateString(), 'status' => $t->status->value,
            ])->values()->all(),
            'contract_signed' => $this->hasSignedContract(),
            'files' => $this->documents->map(fn (Document $d) => [
                'id' => $d->id, 'name' => $d->original_name ?? $d->title, 'title' => $d->title, 'type' => $d->type->value, 'has_file' => $d->file_path !== null,
            ])->values()->all(),
        ];
    }
}
