<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Personal details only HR and the person may see. Emergency contact and
 * passport number are encrypted at rest; nothing here is activity logged.
 *
 * @property int $id
 * @property int $person_id
 * @property string|null $emergency_contact
 * @property string|null $passport_number
 * @property Carbon|null $passport_expiry
 * @property string|null $nationality
 * @property string|null $dietary_requirements
 * @property string|null $access_needs
 */
class PersonRestricted extends Model
{
    protected $table = 'person_restricted';

    protected $fillable = ['person_id', 'emergency_contact', 'passport_number', 'passport_expiry', 'nationality', 'dietary_requirements', 'access_needs'];

    protected $hidden = ['emergency_contact', 'passport_number'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'emergency_contact' => 'encrypted',
            'passport_number' => 'encrypted',
            'passport_expiry' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
