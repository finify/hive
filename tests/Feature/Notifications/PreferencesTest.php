<?php

use App\Actions\Events\ChangeEventStage;
use App\Demo\Workspace;
use App\Enums\EventStage;
use App\Jobs\SendDailyDigest;
use App\Livewire\Settings\Notifications;
use App\Models\Person;
use App\Notifications\DailyDigest;
use App\Notifications\HiveNotice;
use App\Support\NotificationPreferences;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function notice(int $personId, string $type): void
{
    app(Workspace::class)->notify($personId, $type, 'Test '.$type, 'Body', 'my-work');
}

it('emails every type by default and always keeps the bell', function () {
    $prefs = NotificationPreferences::of(Person::query()->findOrFail(10));

    expect($prefs->channels('booking'))->toBe(['database', 'mail'])
        ->and($prefs->digest)->toBeFalse();
});

it('lets people turn email off for a type, keeping the bell', function () {
    Notification::fake();

    Livewire::actingAs(persona('jamie'))->test(Notifications::class)
        ->assertSee('Bookings, releases and travel')
        ->set('email.booking', false)
        ->call('save');

    notice(10, 'booking');
    notice(10, 'leave');

    Notification::assertSentTo(Person::query()->findOrFail(10), HiveNotice::class, fn ($n, array $channels) => $n->type === 'booking' && $channels === ['database']);
    Notification::assertSentTo(Person::query()->findOrFail(10), HiveNotice::class, fn ($n, array $channels) => $n->type === 'leave' && $channels === ['database', 'mail']);
    expect(Activity::query()->where('event', 'notification-preferences-saved')->exists())->toBeTrue();
});

it('sends one daily digest instead of individual emails, with only the chosen types', function () {
    Livewire::actingAs(persona('jamie'))->test(Notifications::class)
        ->set('email.clash', false)
        ->set('digest', true)
        ->call('save');

    Notification::fake();
    notice(10, 'booking');
    notice(10, 'clash');
    Notification::assertSentTo(Person::query()->findOrFail(10), HiveNotice::class, fn ($n, array $channels) => $channels === ['database']);
    Notification::assertSentToTimes(Person::query()->findOrFail(10), HiveNotice::class, 2);
});

it('builds the digest from the last day’s notices and skips people with nothing new', function () {
    $jamie = Person::query()->findOrFail(10);
    $jamie->forceFill(['notification_preferences' => ['email' => ['clash' => false], 'digest' => true]])->save();
    $jamie->notify(new HiveNotice(10, 'booking', 'Booked on EV-26117', 'Lisbon.', 'my-work'));
    $jamie->notify(new HiveNotice(10, 'clash', 'A clash', 'Somewhere.', 'my-work'));
    Person::query()->findOrFail(12)->forceFill(['notification_preferences' => ['digest' => true]])->save();

    Notification::fake();
    (new SendDailyDigest)->handle();

    Notification::assertSentTo($jamie, DailyDigest::class, fn (DailyDigest $d) => collect($d->items)->pluck('title')->contains('Booked on EV-26117')
        && ! collect($d->items)->pluck('title')->contains('A clash'));
    Notification::assertNotSentTo(Person::query()->findOrFail(12), DailyDigest::class);
});

it('tells the producer and team when an event changes stage, not the person who moved it', function () {
    Notification::fake();
    $event = app(Workspace::class)->eventByCode('EV-26126');

    app(ChangeEventStage::class)->handle(persona('priya'), $event['id'], EventStage::ProposalSent);

    Notification::assertSentTo(Person::query()->findOrFail($event['producer_id']), HiveNotice::class, fn ($n) => $n->type === 'stage' && str_contains($n->title, 'EV-26126 is now Proposal sent'));
    Notification::assertNotSentTo(Person::query()->findOrFail(1), HiveNotice::class);
});
