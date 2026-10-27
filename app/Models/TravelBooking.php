<?php

namespace App\Models;

use App\Enums\TravelKind;
use App\Enums\TravelMode;
use App\Enums\TravelStatus;
use App\Support\Display;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One leg of travel or one hotel stay for a person on an event. Its cost is
 * an actual on the event's Travel or Accommodation line, posted through
 * PostCost; changes and cancellations post the difference.
 *
 * @property int $id
 * @property int $event_id
 * @property int $person_id
 * @property TravelKind $kind
 * @property TravelMode $mode
 * @property string|null $from_place
 * @property string|null $to_place
 * @property Carbon|null $depart_at
 * @property Carbon|null $arrive_at
 * @property string|null $provider
 * @property string|null $reference
 * @property Carbon|null $check_in
 * @property Carbon|null $check_out
 * @property int $cost_minor
 * @property string $currency
 * @property TravelStatus $status
 * @property string|null $notes
 * @property int|null $booked_by
 */
class TravelBooking extends Model
{
    protected $fillable = [
        'event_id', 'person_id', 'kind', 'mode', 'from_place', 'to_place', 'depart_at', 'arrive_at', 'provider', 'reference',
        'check_in', 'check_out', 'cost_minor', 'currency', 'status', 'notes', 'booked_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TravelKind::class, 'mode' => TravelMode::class, 'status' => TravelStatus::class,
            'depart_at' => 'datetime', 'arrive_at' => 'datetime', 'check_in' => 'date', 'check_out' => 'date', 'cost_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * One line for notices and My work: "Flight London to Lisbon, 8 Nov 2026 at 07:15".
     */
    public function summary(): string
    {
        if ($this->kind === TravelKind::Accommodation) {
            return ($this->provider ?? 'Hotel').', '.Display::date($this->check_in).' to '.Display::date($this->check_out);
        }

        return $this->mode->label().' '.$this->from_place.' to '.$this->to_place.', '.Display::date($this->depart_at).($this->depart_at ? ' at '.$this->depart_at->format('H:i') : '');
    }

    public function ref(): string
    {
        return 'TR-'.$this->id;
    }
}
