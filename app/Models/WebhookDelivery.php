<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to deliver one event to one endpoint, kept for the log.
 *
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property string $uuid
 * @property string $event
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $error
 * @property Carbon|null $delivered_at
 * @property Carbon $created_at
 * @property-read WebhookEndpoint $endpoint
 */
class WebhookDelivery extends Model
{
    protected $fillable = ['webhook_endpoint_id', 'uuid', 'event', 'payload', 'attempts', 'response_status', 'error', 'delivered_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime', 'attempts' => 'integer', 'response_status' => 'integer'];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
