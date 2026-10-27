<?php

use App\Actions\Events\ChangeEventStage;
use App\Actions\Integrations\ManageWebhooks;
use App\Actions\Resourcing\ConfirmHolds;
use App\Demo\Workspace;
use App\Enums\EventStage;
use App\Jobs\DeliverWebhook;
use App\Livewire\Admin\Integrations;
use App\Models\Assignment;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\Webhooks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function hook(array $events = ['event.stage_changed', 'booking.confirmed']): array
{
    return app(ManageWebhooks::class)->create(persona('sam'), ['url' => 'https://hooks.example.com/hive', 'events' => $events]);
}

it('posts a signed payload when an event changes stage', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 204)]);
    ['secret' => $secret] = hook();
    $event = app(Workspace::class)->eventByCode('EV-26126');

    app(ChangeEventStage::class)->handle(persona('priya'), $event['id'], EventStage::ProposalSent);

    Http::assertSent(function (Request $request) use ($secret) {
        $id = $request->header('webhook-id')[0];
        $ts = (int) $request->header('webhook-timestamp')[0];

        return $request['type'] === 'event.stage_changed'
            && $request['data']['event']['code'] === 'EV-26126'
            && $request['data']['to'] === 'proposal-sent'
            && $request->header('webhook-signature')[0] === 'v1,'.Webhooks::sign($secret, $id, $ts, $request->body());
    });

    expect(WebhookDelivery::query()->sole()->delivered_at)->not->toBeNull();
});

it('tells subscribers when a booking is confirmed, without rates', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    hook(['booking.confirmed']);

    app(ConfirmHolds::class)->handle(persona('hannah'), Assignment::query()->where('event_id', 9)->where('status', 'first-hold')->pluck('id')->all());

    $payloads = WebhookDelivery::query()->pluck('payload');
    expect($payloads)->not->toBeEmpty()
        ->and($payloads->first()['type'])->toBe('booking.confirmed')
        ->and(json_encode($payloads))->not->toContain('rate')->not->toContain('agreed');
});

it('only sends the events an endpoint asked for, and nothing when paused', function () {
    Http::fake();
    ['endpoint' => $endpoint] = hook(['booking.confirmed']);

    app(Webhooks::class)->dispatch('event.stage_changed', ['x' => 1]);
    expect(WebhookDelivery::query()->count())->toBe(0);

    app(ManageWebhooks::class)->toggle(persona('sam'), $endpoint->id);
    app(Webhooks::class)->dispatch('booking.confirmed', ['x' => 1]);
    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('records failures and retries with backoff', function () {
    Http::fake(['hooks.example.com/*' => Http::response('nope', 500)]);
    hook();

    app(Webhooks::class)->dispatch('event.stage_changed', ['x' => 1]);
})->throws(RuntimeException::class, 'answered 500');

it('keeps the secret encrypted and refuses private addresses', function () {
    ['endpoint' => $endpoint, 'secret' => $secret] = hook();

    expect(DB::table('webhook_endpoints')->value('secret'))->not->toContain($secret)
        ->and($endpoint->refresh()->secret)->toBe($secret)
        ->and((new DeliverWebhook(1))->backoff())->toBe([30, 120, 600, 1800]);

    foreach (['https://localhost/x', 'https://127.0.0.1/x', 'https://10.0.0.5/x', 'https://192.168.1.2/x', 'http://hooks.example.com/x'] as $url) {
        expect(fn () => app(ManageWebhooks::class)->create(persona('sam'), ['url' => $url, 'events' => ['booking.confirmed']]))->toThrow(ValidationException::class);
    }

    expect(fn () => app(ManageWebhooks::class)->create(persona('priya'), ['url' => 'https://hooks.example.com', 'events' => ['booking.confirmed']]))
        ->toThrow(AuthorizationException::class);
});

it('manages webhooks from the Integrations page, showing the secret once', function () {
    Http::fake();

    Livewire::actingAs(persona('sam'))->test(Integrations::class)
        ->set('hookForm.url', 'https://hooks.example.com/hive')
        ->call('addWebhook')
        ->assertHasNoErrors()
        ->assertSee('whsec_')
        ->call('testWebhook', WebhookEndpoint::query()->sole()->id)
        ->assertSee('ping');

    Livewire::actingAs(persona('priya'))->test(Integrations::class)->assertDontSee('Add webhook')->assertSee('hooks.example.com');
});
