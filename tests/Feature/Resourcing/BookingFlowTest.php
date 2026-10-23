<?php

use App\Actions\Resourcing\BookPerson;
use App\Actions\Resourcing\ConfirmHolds;
use App\Actions\Resourcing\ReleaseAssignment;
use App\Actions\Resourcing\SaveCrewCall;
use App\Enums\AssignmentStatus;
use App\Livewire\Events\Crew;
use App\Livewire\Resourcing\ResolveClashModal;
use App\Livewire\Resourcing\Rota;
use App\Models\Assignment;
use App\Models\CrewCall;
use App\Models\Event;
use App\Models\LeaveRequest;
use App\Models\Person;
use App\Support\DateRange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** Notices stored for a person, newest first. */
function noticesFor(int $personId): array
{
    return Person::query()->findOrFail($personId)->notifications()->latest()->get()->pluck('data.title')->all();
}

it('shows an overlapping booking as a clash on the rota, and the resolve flow reassigns it and tells both people (phase 3 done)', function () {
    $hannah = persona('hannah');

    // Hannah needs a London pre-rig for the Lisbon congress on 5 Nov.
    $crew = Livewire::actingAs($hannah)->test(Crew::class, ['eventId' => 9])
        ->call('startCall')
        ->set('callForm.role_name', 'AV Technician')
        ->set('callForm.quantity', 1)
        ->set('callForm.start_date', '2026-11-05')
        ->set('callForm.end_date', '2026-11-05')
        ->set('callForm.call_time', '08:00')
        ->set('callForm.wrap_time', '18:00')
        ->set('callForm.location', 'Lumen warehouse, Park Royal')
        ->call('saveCall')
        ->assertHasNoErrors();

    $call = CrewCall::query()->where('event_id', 9)->whereDate('start_date', '2026-11-05')->firstOrFail();

    // Mei Lin is confirmed on EV-26118 that day: booking her needs a reason.
    $crew->call('startBooking', $call->id)
        ->set('booking.person_id', '14')
        ->assertSee('This would clash.')
        ->call('book')
        ->assertHasErrors('booking.clash_override_reason');

    $crew->set('booking.clash_override_reason', 'Only Mei Lin knows the Lisbon media server set-up; sorting cover now.')
        ->call('book')
        ->assertHasNoErrors();

    $booked = Assignment::query()->where('crew_call_id', $call->id)->where('person_id', 14)->firstOrFail();
    expect($booked->status)->toBe(AssignmentStatus::FirstHold)
        ->and($booked->clash_note)->toStartWith('Only Mei Lin')
        ->and($booked->clash_override_reason)->toBeNull();

    // The producer of the other event hears about it.
    expect(noticesFor(6))->toContain('Mei Lin Chow is double-booked');

    // The clash is on the rota, outlined in red.
    Livewire::actingAs($hannah)->test(Rota::class)
        ->set('start', '2026-11-02')
        ->assertSee('Clash for Mei Lin Chow', false);

    $clash = collect($this->workspace()->clashes())->first(fn ($c) => $c->personId === 14);
    expect($clash)->not->toBeNull();

    // Resolve: move the new booking to the first person suggested.
    $replacement = $this->workspace()->availablePeople('AV Technician', DateRange::of('2026-11-05', '2026-11-05'), preferCountry: 'United Kingdom')->first()['person']['id'];

    Livewire::actingAs($hannah)->test(ResolveClashModal::class)
        ->call('open', $clash->key())
        ->set('assignmentId', $booked->id)
        ->set('replacementId', $replacement)
        ->call('resolve')
        ->assertHasNoErrors();

    expect($booked->refresh()->status)->toBe(AssignmentStatus::Released)
        ->and(Assignment::query()->where('crew_call_id', $call->id)->where('person_id', $replacement)->where('status', 'confirmed')->exists())->toBeTrue()
        ->and(collect($this->workspace()->clashes())->contains(fn ($c) => $c->personId === 14))->toBeFalse()
        ->and(noticesFor(14))->toContain('Released from EV-26117 on 5 Nov 2026')
        ->and(noticesFor($replacement))->toContain('Booked on EV-26117');

    expect(Activity::query()->where('properties->ref', 'EV-26117')->pluck('event')->all())
        ->toContain('crew-call-added', 'booked', 'clash-resolved');
});

it('sends freelancers a booking request with the dates, location, role and rate', function () {
    $assignment = app(BookPerson::class)->handle(persona('hannah'), CrewCall::query()->findOrFail(25), [
        'person_id' => 29, 'start_date' => '2026-11-09', 'end_date' => '2026-11-11', 'status' => 'confirmed',
    ]);

    // Freelancers confirm by accepting, so this starts as a hold awaiting an answer.
    expect($assignment->status)->toBe(AssignmentStatus::FirstHold)->and($assignment->responded_at)->toBeNull();

    $notice = Person::query()->findOrFail(29)->notifications()->latest()->firstOrFail();
    expect($notice->data['title'])->toBe('Booking request: EV-26117')
        ->and($notice->data['body'])->toContain('9–11 Nov 2026')->toContain('as AV Technician')->toContain(' a day');
    $this->assertDatabaseHas('activity_log', ['event' => 'booking-requested', 'log_name' => 'resourcing']);
});

it('keeps bookings on unconfirmed events as holds (rule 3)', function () {
    // EV-26123 is at proposal stage; Mei Lin is an employee.
    $call = CrewCall::query()->create(['event_id' => 14, 'role_name' => 'AV Technician', 'quantity' => 1, 'start_date' => '2027-02-01', 'end_date' => '2027-02-02', 'call_time' => '08:00', 'wrap_time' => '18:00']);

    expect(fn () => app(BookPerson::class)->handle(persona('hannah'), $call, ['person_id' => 14, 'start_date' => '2027-02-01', 'end_date' => '2027-02-02', 'status' => 'confirmed']))
        ->toThrow(ValidationException::class, 'EV-26123 is not confirmed yet');

    expect(app(BookPerson::class)->handle(persona('hannah'), $call, ['person_id' => 14, 'start_date' => '2027-02-01', 'end_date' => '2027-02-02', 'status' => 'first-hold'])->status)
        ->toBe(AssignmentStatus::FirstHold);
});

it('blocks a confirmed booking over approved leave (rule 2)', function () {
    LeaveRequest::query()->create(['person_id' => 14, 'leave_type' => 'annual', 'start_date' => '2026-11-10', 'end_date' => '2026-11-11', 'days' => 2, 'status' => 'approved']);

    expect(fn () => app(BookPerson::class)->handle(persona('hannah'), CrewCall::query()->findOrFail(25), [
        'person_id' => 14, 'start_date' => '2026-11-09', 'end_date' => '2026-11-13', 'status' => 'confirmed',
    ]))->toThrow(ValidationException::class, 'Approved leave from 10 Nov 2026 to 11 Nov 2026.');
});

it('records the reason as an accepted overlap when confirmed is booked over confirmed (rule 1)', function () {
    // Kofi is confirmed on EV-26114 (Northwind, Hannah's) to 24 Oct; Mei Lin on 20–24 Oct too.
    $call = CrewCall::query()->create(['event_id' => 7, 'role_name' => 'AV Technician', 'quantity' => 1, 'start_date' => '2026-10-24', 'end_date' => '2026-10-24', 'call_time' => '19:00', 'wrap_time' => '23:00']);
    $book = fn (?string $reason) => app(BookPerson::class)->handle(persona('hannah'), $call, ['person_id' => 14, 'start_date' => '2026-10-24', 'end_date' => '2026-10-24', 'status' => 'confirmed', 'clash_override_reason' => $reason]);

    expect(fn () => $book(null))->toThrow(ValidationException::class);

    $assignment = $book('Evening strike only, after her day shift on the same site.');

    expect($assignment->clash_override_reason)->toBe('Evening strike only, after her day shift on the same site.')
        ->and(collect($this->workspace()->clashes())->contains(fn ($c) => $c->personId === 14 && str_contains($c->key(), 'assignment:'.$assignment->id)))->toBeFalse();
});

it('warns about working time when a booking pattern breaks the limits', function () {
    $call = CrewCall::query()->create(['event_id' => 9, 'role_name' => 'AV Technician', 'quantity' => 1, 'start_date' => '2026-11-14', 'end_date' => '2026-11-14', 'call_time' => '06:00', 'wrap_time' => '20:00']);

    // Inês is held 9–13 Nov, 08:00–19:00; a 06:00 call on the 14th leaves 11 hours' rest, a 14-hour day is long.
    $preview = app(BookPerson::class)->preview($call, 28, '2026-11-14', '2026-11-14', AssignmentStatus::FirstHold);

    expect($preview['warnings'])->toContain('14 Nov: 13.7-hour shift, over the 12-hour limit')
        ->and($preview['clashes'])->toBe([]);

    $early = CrewCall::query()->create(['event_id' => 9, 'role_name' => 'AV Technician', 'quantity' => 1, 'start_date' => '2026-11-14', 'end_date' => '2026-11-14', 'call_time' => '05:00', 'wrap_time' => '12:00']);
    expect(app(BookPerson::class)->preview($early, 28, '2026-11-14', '2026-11-14', AssignmentStatus::FirstHold)['warnings'])
        ->toContain('14 Nov: Only 10 hours’ rest since the previous shift');
});

it('lets only the event’s producer or crew managers book and change crew calls', function () {
    $call = CrewCall::query()->findOrFail(25);

    expect(fn () => app(BookPerson::class)->handle(persona('marcus'), $call, ['person_id' => 29, 'start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'status' => 'first-hold']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveCrewCall::class)->handle(persona('kofi'), Event::query()->findOrFail(9), ['role_name' => 'Rigger']))
        ->toThrow(AuthorizationException::class);

    // The operations director manages all crew.
    expect(app(BookPerson::class)->handle(persona('priya'), $call, ['person_id' => 29, 'start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'status' => 'first-hold']))->toBeInstanceOf(Assignment::class);
});

it('validates crew calls, and won’t shrink or remove one below who is booked', function () {
    $event = Event::query()->findOrFail(9);
    $save = app(SaveCrewCall::class);

    expect(fn () => $save->handle(persona('hannah'), $event, ['role_name' => '', 'quantity' => 0, 'start_date' => '2026-11-10', 'end_date' => '2026-11-09', 'call_time' => '8am', 'wrap_time' => '18:00']))
        ->toThrow(ValidationException::class);

    // Call 25 has Inês and Kofi on first hold.
    $call = CrewCall::query()->findOrFail(25);
    expect(fn () => $save->handle(persona('hannah'), $event, ['role_name' => 'AV Technician', 'quantity' => 1, 'start_date' => '2026-11-09', 'end_date' => '2026-11-13', 'call_time' => '08:00', 'wrap_time' => '19:00'], $call))
        ->toThrow(ValidationException::class, '2 people are already booked');
    expect(fn () => $save->delete(persona('hannah'), $call))->toThrow(ValidationException::class);
});

it('releases a booking and tells the person', function () {
    app(ReleaseAssignment::class)->handle(persona('hannah'), 46);

    expect(Assignment::query()->findOrFail(46)->status)->toBe(AssignmentStatus::Released)
        ->and(noticesFor(28))->toContain('Released from EV-26117');
});

it('bulk-confirms first holds once the event is confirmed, leaving the ones it can’t', function () {
    $confirm = app(ConfirmHolds::class);
    $holds = $confirm->firstHoldsOn(12);
    expect($holds)->toHaveCount(2);

    $result = $confirm->handle(persona('priya'), $holds);

    expect($result['confirmed'] + count($result['skipped']))->toBe(2)
        ->and(Assignment::query()->whereIn('id', $holds)->where('status', 'confirmed')->count())->toBe($result['confirmed']);
});

it('won’t confirm a freelancer who hasn’t answered the booking request', function () {
    // Kofi hasn't answered the Lisbon request; EV-26117 is in pre-production.
    $result = app(ConfirmHolds::class)->handle(persona('hannah'), [47]);

    expect($result['confirmed'])->toBe(0)
        ->and($result['skipped']['Kofi Mensah'])->toContain('They haven’t accepted the booking request yet.');
});
