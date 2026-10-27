<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somewhere Hive posts signed events to (section 5.13).
 *
 * @property int $id
 * @property string $url
 * @property string $secret encrypted at rest
 * @property list<string> $events
 * @property bool $is_active
 * @property string|null $description
 * @property int|null $created_by
 */
class WebhookEndpoint extends Model
{
    protected $fillable = ['url', 'secret', 'events', 'is_active', 'description', 'created_by'];

    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'events' => 'array', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
