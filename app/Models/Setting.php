<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin's change to a configuration value (section 5.15). Applied over
 * config/hive.php when the app boots.
 *
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
