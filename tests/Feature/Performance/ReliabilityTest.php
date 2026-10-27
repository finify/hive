<?php

use App\Demo\Workspace;
use App\Jobs\DeliverWebhook;
use App\Jobs\FlagRecordsPastRetention;
use App\Jobs\RefreshEventHealth;
use App\Jobs\SendDailyDigest;
use App\Livewire\Admin\Queue;
use App\Models\Person;
use App\Notifications\HiveNotice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('answers the uptime check when the database and cache are up', function () {
    $this->get('/up')->assertOk();
});

it('retries only jobs that are safe to run twice', function () {
    expect((new FlagRecordsPastRetention)->tries)->toBe(3)
        ->and((new DeliverWebhook(1))->tries)->toBe(5)
        ->and((new SendDailyDigest)->tries)->toBe(1)
        ->and((new RefreshEventHealth)->tries)->toBe(1);
});

it('tells the producer and director when an event’s health slips, once', function () {
    $event = app(Workspace::class)->eventByCode('EV-26104');
    expect(app(Workspace::class)->health($event['id'])->value)->toBe('at-risk');

    Cache::forever("event-health.{$event['id']}", 'on-track');
    Notification::fake();

    expect((new RefreshEventHealth)->handle(app(Workspace::class)))->toBeGreaterThan(0);
    Notification::assertSentTo(Person::query()->findOrFail($event['producer_id']), HiveNotice::class, fn ($n) => $n->type === 'budget' && $n->title === 'EV-26104 is now at risk');
    Notification::assertSentTo(Person::query()->findOrFail(1), HiveNotice::class, fn ($n) => $n->type === 'budget');

    expect((new RefreshEventHealth)->handle(app(Workspace::class)))->toBe(0);
});

it('shows failed jobs to admins, who can clear them', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => 'f1b2c3d4-0000-4000-8000-000000000001', 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\DeliverWebhook']), 'exception' => "RuntimeException: Webhook endpoint answered 500\n#0 trace", 'failed_at' => now(),
    ]);

    Livewire::actingAs(persona('sam'))->test(Queue::class)
        ->assertSee('DeliverWebhook')
        ->assertSee('Webhook endpoint answered 500')
        ->call('forget', 'f1b2c3d4-0000-4000-8000-000000000001')
        ->assertDontSee('DeliverWebhook');

    Livewire::actingAs(persona('priya'))->test(Queue::class)->assertDontSee('Retry');
    $this->actingAs(persona('hannah'))->get(route('queue'))->assertForbidden();
});
