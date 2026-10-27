<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\Webhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Posts one webhook delivery, retrying with backoff over about an hour.
 * Any 2xx response counts as delivered.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || $delivery->delivered_at !== null || ! $delivery->endpoint->is_active) {
            return;
        }

        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $delivery->increment('attempts');

        try {
            $response = Http::timeout(10)->withoutRedirecting()
                ->withHeaders(Webhooks::headers($delivery, $delivery->endpoint->secret, $body, now()->timestamp))
                ->withBody($body, 'application/json')
                ->post($delivery->endpoint->url);
        } catch (Throwable $e) {
            $delivery->update(['error' => mb_substr($e->getMessage(), 0, 250)]);

            throw $e;
        }

        $delivery->update(['response_status' => $response->status(), 'error' => $response->successful() ? null : 'HTTP '.$response->status()]);

        if (! $response->successful()) {
            throw new RuntimeException("Webhook endpoint answered {$response->status()}");
        }

        $delivery->update(['delivered_at' => now()]);
    }
}
