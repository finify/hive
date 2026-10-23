<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A freelancer's day rate for a role, effective from a date. Costs already
 * posted keep their own amount, so a new rate never changes them.
 *
 * @property int $id
 * @property int $person_id
 * @property string $role_name
 * @property int $amount_minor
 * @property string $currency
 * @property Money $amount
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
class DayRate extends Model
{
    use LogsActivity;

    protected $fillable = ['person_id', 'role_name', 'amount_minor', 'currency', 'effective_from', 'effective_to'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'amount' => MoneyCast::class.':amount_minor,currency',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('people')->logOnly(['person_id', 'role_name', 'amount_minor', 'currency', 'effective_from', 'effective_to'])->logOnlyDirty();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
