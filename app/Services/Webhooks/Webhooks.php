<?php

namespace App\Services\Webhooks;

use App\Demo\Workspace;
use App\Jobs\DeliverWebhook;
use App\Models\Assignment;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * Outbound webhooks. Payloads carry codes, names, dates and statuses, never
 * rates or restricted fields. Each is signed like Standard Webhooks: the
 * signature is an HMAC-SHA256 of "{id}.{timestamp}.{body}" with the
 * endpoint's secret, sent with the id and timestamp in headers.
 */
class Webhooks
{
    public const EVENTS = [
        'event.stage_changed' => 'An event changes stage',
        'booking.confirmed' => 'A booking is confirmed',
    ];

    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function dispatch(string $event, array $data): int
    {
        $count = 0;

        foreach (WebhookEndpoint::query()->where('is_active', true)->get() as $endpoint) {
            if (! in_array($event, $endpoint->events, true)) {
                continue;
            }

            $delivery = $endpoint->deliveries()->create([
                'uuid' => (string) Str::uuid(),
                'event' => $event,
                'payload' => ['type' => $event, 'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'data' => $data],
            ]);

            DeliverWebhook::dispatch($delivery->id)->afterCommit();
            $count++;
        }

        return $count;
    }

    public function stageChanged(int $eventId, string $from, string $to): void
    {
        $event = $this->workspace->event($eventId);

        if ($event) {
            $this->dispatch('event.stage_changed', [
                'event' => ['code' => $event['code'], 'name' => $event['name'], 'start_date' => $event['start_date'], 'end_date' => $event['end_date']],
                'from' => $from, 'to' => $to,
            ]);
        }
    }

    public function bookingConfirmed(Assignment $assignment): void
    {
        $event = $this->workspace->event($assignment->event_id);
        $call = $this->workspace->crewCalls()->get($assignment->crew_call_id);

        $this->dispatch('booking.confirmed', [
            'booking' => ['id' => $assignment->id, 'start_date' => $assignment->start_date->toDateString(), 'end_date' => $assignment->end_date->toDateString(), 'role' => $call['role_name'] ?? null],
            'event' => ['code' => $event['code'] ?? null, 'name' => $event['name'] ?? null],
            'person' => ['id' => $assignment->person_id, 'name' => $this->workspace->personName($assignment->person_id)],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function headers(WebhookDelivery $delivery, string $secret, string $body, int $timestamp): array
    {
        return [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Hive-Webhooks/1.0',
            'webhook-id' => $delivery->uuid,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => 'v1,'.self::sign($secret, $delivery->uuid, $timestamp, $body),
            'Hive-Event' => $delivery->event,
        ];
    }

    public static function sign(string $secret, string $id, int $timestamp, string $body): string
    {
        return base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $secret, true));
    }
}
