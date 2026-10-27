<?php

namespace App\Models;

use App\Models\Concerns\TouchesWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dates a freelancer has marked themselves as not available.
 *
 * @property int $id
 * @property int $person_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string|null $note
 */
class Unavailability extends Model
{
    use TouchesWorkspace;

    protected $table = 'unavailability';

    protected $fillable = ['person_id', 'start_date', 'end_date', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
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
        return ['id' => $this->id, 'person_id' => $this->person_id, 'start_date' => $this->start_date->toDateString(), 'end_date' => $this->end_date->toDateString(), 'note' => $this->note];
    }
}
