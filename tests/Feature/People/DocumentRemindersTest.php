<?php

use App\Jobs\SendDocumentExpiryReminders;
use App\Models\Document;
use App\Models\Person;
use App\Notifications\DocumentExpiring;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    // Kofi's only expiring document, due in exactly 30 days.
    Document::query()->update(['expires_at' => null]);
    $this->doc = Person::query()->findOrFail(26)->documents()->firstOrFail();
    $this->doc->update(['expires_at' => '2026-11-22']);
});

it('reminds the person and HR 30 days before expiry, once', function () {
    Notification::fake();
    $this->travelTo('2026-10-23 07:00');

    (new SendDocumentExpiryReminders)->handle();
    (new SendDocumentExpiryReminders)->handle();

    Notification::assertSentToTimes(persona('kofi'), DocumentExpiring::class, 1);
    Notification::assertSentToTimes(persona('leah'), DocumentExpiring::class, 1);
    Notification::assertNotSentTo(persona('hannah'), DocumentExpiring::class);
    expect($this->doc->fresh()->reminded_days)->toBe(30);
});

it('reminds again 7 days before', function () {
    Notification::fake();
    $this->travelTo('2026-10-23 07:00');
    (new SendDocumentExpiryReminders)->handle();

    $this->travelTo('2026-11-15 07:00');
    (new SendDocumentExpiryReminders)->handle();

    Notification::assertSentToTimes(persona('kofi'), DocumentExpiring::class, 2);
    expect($this->doc->fresh()->reminded_days)->toBe(7);
});

it('sends nothing for documents further out or already expired', function () {
    Notification::fake();
    $this->travelTo('2026-10-01 07:00');
    (new SendDocumentExpiryReminders)->handle();

    $this->travelTo('2026-11-23 07:00');
    (new SendDocumentExpiryReminders)->handle();

    Notification::assertNothingSent();
});

it('puts the reminder in the bell', function () {
    $this->travelTo('2026-10-23 07:00');
    (new SendDocumentExpiryReminders)->handle();

    $this->actingAs(persona('kofi'))->get('/notifications')->assertSee('Your Public liability insurance £5m expires in 30 days');
});

it('is scheduled daily at 07:00 UK time', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'document-expiry-reminders');

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 7 * * *')->and($event->timezone)->toBe('Europe/London');
});
