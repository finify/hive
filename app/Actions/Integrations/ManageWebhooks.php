<?php

namespace App\Actions\Integrations;

use App\Jobs\DeliverWebhook;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\Webhooks;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admins add, pause and remove webhook endpoints, and send a test. The
 * signing secret is shown once when the endpoint is added. Endpoints must
 * be HTTPS on a public address, so Hive can't be pointed at its own network.
 */
class ManageWebhooks
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{endpoint: WebhookEndpoint, secret: string}
     */
    public function create(User $user, array $input): array
    {
        Gate::forUser($user)->authorize('settings.manage');

        $data = Validator::make($input, [
            'url' => ['required', 'url:https', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
                if (! self::isPublic((string) $value)) {
                    $fail('Use a public HTTPS address, not a local or private one.');
                }
            }],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(Webhooks::EVENTS))],
            'description' => ['nullable', 'string', 'max:120'],
        ], ['url.url' => 'Use an address starting https://.', 'events.required' => 'Choose at least one event.'])->validate();

        $secret = 'whsec_'.base64_encode(random_bytes(24));
        $endpoint = WebhookEndpoint::query()->create([
            'url' => $data['url'], 'secret' => $secret, 'events' => array_values($data['events']),
            'description' => $data['description'] ?? null, 'is_active' => true, 'created_by' => $user->person_id,
        ]);

        activity('integrations')->causedBy($user)->performedOn($endpoint)
            ->withProperties(['ref' => 'WH-'.$endpoint->id, 'attributes' => ['url' => $endpoint->url, 'events' => $endpoint->events]])
            ->event('webhook-added')->log("Added a webhook to {$endpoint->url}");

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    public function toggle(User $user, int $id): WebhookEndpoint
    {
        Gate::forUser($user)->authorize('settings.manage');
        $endpoint = WebhookEndpoint::query()->findOrFail($id);
        $endpoint->update(['is_active' => ! $endpoint->is_active]);

        activity('integrations')->causedBy($user)->performedOn($endpoint)
            ->withProperties(['ref' => 'WH-'.$id, 'old' => ['is_active' => ! $endpoint->is_active], 'attributes' => ['is_active' => $endpoint->is_active]])
            ->event('webhook-'.($endpoint->is_active ? 'resumed' : 'paused'))->log(($endpoint->is_active ? 'Resumed' : 'Paused')." the webhook to {$endpoint->url}");

        return $endpoint;
    }

    public function delete(User $user, int $id): void
    {
        Gate::forUser($user)->authorize('settings.manage');
        $endpoint = WebhookEndpoint::query()->findOrFail($id);
        $endpoint->delete();

        activity('integrations')->causedBy($user)
            ->withProperties(['ref' => 'WH-'.$id, 'old' => ['url' => $endpoint->url]])
            ->event('webhook-removed')->log("Removed the webhook to {$endpoint->url}");
    }

    public function test(User $user, int $id): void
    {
        Gate::forUser($user)->authorize('settings.manage');
        $endpoint = WebhookEndpoint::query()->findOrFail($id);

        $delivery = $endpoint->deliveries()->create([
            'uuid' => (string) Str::uuid(), 'event' => 'ping',
            'payload' => ['type' => 'ping', 'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'data' => ['message' => 'A test from Hive.']],
        ]);

        DeliverWebhook::dispatch($delivery->id);
    }

    /**
     * False for localhost, private, reserved and link-local addresses, by
     * name or by what the name resolves to.
     */
    public static function isPublic(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return false;
        }

        $ips = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) ? [trim($host, '[]')] : (gethostbynamel($host) ?: []);

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
