<?php

use App\Actions\Events\ChangeEventStage;
use App\Actions\Resourcing\AcceptClash;
use App\Actions\Resourcing\ConfirmAssignment;
use App\Actions\Resourcing\ResolveClash;
use App\Actions\Resourcing\RespondToBookingRequest;
use App\Enums\EventStage;
use App\Models\Event;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

it('shows Kofi double-booked on Friday 23 October', function () {
    $clashes = $this->workspace()->clashes();

    expect($clashes)->toHaveCount(1)
        ->and($clashes[0]->personId)->toBe(26)
        ->and($clashes[0]->dates->format())->toBe('23 Oct 2026')
        ->and(collect($clashes[0]->eventIds())->sort()->values()->all())->toBe([7, 8]);
});

it('closes the clash once a reason is recorded (rule 1)', function () {
    $clash = $this->workspace()->clashes()[0];

    app(AcceptClash::class)->handle(persona('hannah'), $clash->key(), (int) $clash->first->id, 'Covers the morning at Riverside, travels for the evening reveal.');

    expect($this->workspace()->clashes())->toBe([]);
});

it('needs a reason to keep both bookings', function () {
    $clash = $this->workspace()->clashes()[0];

    app(AcceptClash::class)->handle(persona('hannah'), $clash->key(), (int) $clash->first->id, '');
})->throws(ValidationException::class);

it('resolves a clash by moving the days to someone free and notifying both', function () {
    $clash = $this->workspace()->clashes()[0];
    $northwind = $clash->first->eventId === 7 ? $clash->first : $clash->second;

    $newId = app(ResolveClash::class)->handle(persona('hannah'), $clash->key(), (int) $northwind->id, 30);

    $ws = $this->workspace();
    expect($ws->clashes())->toBe([])
        ->and($ws->assignment((int) $northwind->id)['start_date'])->toBe('2026-10-20')
        ->and($ws->assignment((int) $northwind->id)['end_date'])->toBe('2026-10-22')
        ->and($ws->assignment($newId))->toMatchArray(['person_id' => 30, 'event_id' => 7, 'start_date' => '2026-10-23', 'status' => 'confirmed'])
        ->and($ws->notificationsFor(26)->first()['title'])->toBe('Released from EV-26114 on 23 Oct 2026')
        ->and($ws->notificationsFor(30)->first()['title'])->toBe('Booked on EV-26114');

    $this->assertDatabaseHas('activity_log', ['event' => 'clash-resolved', 'log_name' => 'resourcing']);
});

it('keeps the days before and after the clash with the original person', function () {
    $clash = $this->workspace()->clashes()[0];
    $northwind = $clash->first->eventId === 7 ? $clash->first : $clash->second;

    app(ResolveClash::class)->handle(persona('hannah'), $clash->key(), (int) $northwind->id, 30);

    $kofi = $this->workspace()->assignments()->where('person_id', 26)->where('event_id', 7)->sortBy('start_date')->values();
    expect($kofi->map(fn ($a) => $a['start_date'].'/'.$a['end_date'])->all())->toBe(['2026-10-20/2026-10-22', '2026-10-24/2026-10-24']);
});

it('only lets the producer or crew managers move a booking', function () {
    $clash = $this->workspace()->clashes()[0];
    $northwind = $clash->first->eventId === 7 ? $clash->first : $clash->second;

    app(ResolveClash::class)->handle(persona('marcus'), $clash->key(), (int) $northwind->id, 30);
})->throws(AuthorizationException::class);

it('refuses a replacement who is not free', function () {
    $clash = $this->workspace()->clashes()[0];
    $northwind = $clash->first->eventId === 7 ? $clash->first : $clash->second;

    // Arjun Mehta is already on EV-26114 that day.
    app(ResolveClash::class)->handle(persona('hannah'), $clash->key(), (int) $northwind->id, 31);
})->throws(ValidationException::class);

it('blocks confirmation over approved leave (rule 2)', function () {
    // Jamie is booked on EV-26118 from 2 Nov; give them approved leave that week.
    $assignment = $this->workspace()->assignments()->first(fn ($a) => $a['person_id'] === 10 && $a['event_id'] === 10);
    $this->workspace()->ledger->put('assignments', $assignment['id'], ['status' => 'first-hold']);
    $this->workspace()->ledger->put('leave', 1, ['start_date' => '2026-11-02', 'end_date' => '2026-11-06']);

    expect(app(ConfirmAssignment::class)->blockers($assignment['id']))->toContain('Approved leave from 2 Nov 2026 to 6 Nov 2026.');
});

it('keeps holds on events that are not confirmed (rule 3)', function () {
    Event::query()->whereKey(9)->update(['stage' => EventStage::ProposalSent]);
    $this->workspace()->forget();
    $kofiLisbon = $this->workspace()->assignments()->first(fn ($a) => $a['person_id'] === 26 && $a['event_id'] === 9);

    $status = app(RespondToBookingRequest::class)->handle(persona('kofi'), $kofiLisbon['id'], true);

    expect($status->value)->toBe('first-hold')
        ->and($this->workspace()->assignment($kofiLisbon['id'])['responded_at'])->not->toBeNull();
});

it('confirms an accepted booking on a confirmed event', function () {
    $kofiLisbon = $this->workspace()->assignments()->first(fn ($a) => $a['person_id'] === 26 && $a['event_id'] === 9);

    $status = app(RespondToBookingRequest::class)->handle(persona('kofi'), $kofiLisbon['id'], true);

    expect($status->value)->toBe('confirmed')
        ->and($this->workspace()->notificationsFor(4)->first()['title'])->toBe('Kofi Mensah accepted EV-26117');
});

it('blocks confirmation while mandatory documents are expired (rule 19)', function () {
    $ola = $this->workspace()->assignments()->first(fn ($a) => $a['person_id'] === 27 && $a['event_id'] === 9);

    expect(app(ConfirmAssignment::class)->blockers($ola['id']))->toContain('Public liability insurance £5m expired on 11 Oct 2026.');

    config(['hive.documents.expired_mandatory' => 'warn']);
    expect(app(ConfirmAssignment::class)->blockers($ola['id']))->toBe([]);
});

it('releases every booking and notifies people when an event is cancelled (rule 4)', function () {
    $booked = $this->workspace()->assignments()->where('event_id', 9)->filter(fn ($a) => ! in_array($a['status'], ['released', 'declined'], true));

    app(ChangeEventStage::class)->handle(persona('hannah'), 9, EventStage::Cancelled, 'Client postponed to 2027.');

    $ws = $this->workspace();
    expect($ws->event(9)['stage'])->toBe('cancelled')
        ->and($ws->assignments()->where('event_id', 9)->pluck('status')->unique()->values()->all())->toBe(['released']);

    foreach ($booked->pluck('person_id')->unique() as $personId) {
        expect($ws->notificationsFor($personId)->pluck('title'))->toContain('Released from EV-26117');
    }

    expect($ws->notificationsFor(20)->pluck('title'))->toContain('Record cancellation fees for EV-26117');
});

it('lets freelancers answer only their own booking requests (rule 18)', function () {
    $kofiLisbon = $this->workspace()->assignments()->first(fn ($a) => $a['person_id'] === 26 && $a['event_id'] === 9);

    app(RespondToBookingRequest::class)->handle(persona('jamie'), $kofiLisbon['id'], false);
})->throws(AuthorizationException::class);
