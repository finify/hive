<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A public holiday in one region (england-and-wales, scotland,
 * northern-ireland). People take their region's holidays off.
 *
 * @property int $id
 * @property string $region
 * @property Carbon $date
 * @property string $name
 */
class BankHoliday extends Model
{
    public const REGIONS = [
        'england-and-wales' => 'England and Wales',
        'scotland' => 'Scotland',
        'northern-ireland' => 'Northern Ireland',
    ];

    protected $fillable = ['region', 'date', 'name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
