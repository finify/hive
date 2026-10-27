<?php

namespace App\Models;

use App\Models\Concerns\TouchesWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A rate entered by finance: one unit of the currency in GBP from a date.
 *
 * @property int $id
 * @property string $currency
 * @property string $rate_to_gbp
 * @property Carbon $effective_on
 * @property int|null $set_by
 */
class ExchangeRate extends Model
{
    use TouchesWorkspace;

    protected $fillable = ['currency', 'rate_to_gbp', 'effective_on', 'set_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['effective_on' => 'date'];
    }
}
