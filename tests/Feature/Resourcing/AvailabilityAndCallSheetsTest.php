<?php

use App\Actions\People\DecideLeaveRequest;
use App\Actions\People\RequestLeave;
use App\Actions\Resourcing\CallSheet;
use App\Actions\Resourcing\SetUnavailability;
use App\Jobs\SendWeeklyRotaDigest;
use App\Livewire\MyWork;
use App\Livewire\People\Leave;
use App\Livewire\Resourcing\Availability;
use App\Models\Assignment;
use App\Models\LeaveRequest;
use App\Models\Person;
use App\Models\Unavailability;
use App\Notifications\CallSheetIssued;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('lets freelancers mark themselves unavailable, which keeps them out of availability searches', function () {
    Livewire::actingAs(persona('kofi'))->test(MyWork::class)
        ->set('unavailableForm', ['start_date' => '2026-11-16', 'end_date' => '2026-11-18', 'note' => 'Family wedding'])
        ->call('addUnavailable')
        ->assertHasNoErrors()
        ->assertSee('Family wedding');

    $free = $this->workspace()->availablePeople('AV Technician', DateRange::of('2026-11-17', '2026-11-17'))->pluck('person.id');
    expect($free)->not->toContain(26);

    // Employees use leave; nobody removes someone else's dates.
    expect(fn () => app(SetUnavailability::class)->add(persona('jamie'), ['start_date' => '2026-11-16', 'end_date' => '2026-11-16']))
        ->toThrow(HttpException::class);

    $row = Unavailability::query()->where('person_id', 26)->latest('id')->firstOrFail();
    expect(fn () => app(SetUnavailability::class)->remove(persona('jamie'), $row->id))
        ->toThrow(ModelNotFoundException::class);

    app(SetUnavailability::class)->remove(persona('kofi'), $row->id);
    expect(Unavailability::query()->whereKey($row->id)->exists())->toBeFalse();
});

it('rejects unavailability in the past', function () {
    Livewire::actingAs(persona('kofi'))->test(MyWork::class)
        ->set('unavailableForm', ['start_date' => '2026-10-01', 'end_date' => '2026-10-02', 'note' => ''])
        ->call('addUnavailable')
        ->assertHasErrors('unavailableForm.start_date');
});

it('takes a leave request, counting working days only, and the line manager decides', function () {
    // 21 Dec 2026 to 1 Jan 2027: 10 weekdays less Christmas, Boxing Day (substitute 28th) and New Year's Day.
    Livewire::actingAs(persona('jamie'))->test(Leave::class)
        ->set('leaveForm', ['leave_type' => 'annual', 'start_date' => '2026-12-21', 'end_date' => '2027-01-01', 'reason' => 'Christmas'])
        ->call('requestLeave')
        ->assertHasNoErrors();

    $leave = LeaveRequest::query()->where('person_id', 10)->latest('id')->firstOrFail();
    $holidays = collect(json_decode(file_get_contents(database_path('demo/bank_holidays.json')), true))
        ->filter(fn ($b) => $b['region'] === 'england-and-wales' && $b['date'] >= '2026-12-21' && $b['date'] <= '2027-01-01' && CarbonImmutable::parse($b['date'])->isWeekday())->count();

    expect((float) $leave->days)->toBe((float) (10 - $holidays))
        ->and($leave->status->value)->toBe('pending');

    $manager = $this->workspace()->person(10)['line_manager_id'];
    expect(Person::query()->findOrFail($manager)->notifications()->latest()->first()->data['title'])->toBe('Jamie Ross asked for leave');

    app(DecideLeaveRequest::class)->handle(persona('leah'), $leave->id, true);
    expect($leave->refresh()->status->value)->toBe('approved')->and($leave->approved_at)->not->toBeNull();

    // Approved leave now holds Jamie on the rota.
    expect(collect($this->workspace()->bookings())->contains(fn ($b) => $b->personId === 10 && $b->kind === 'leave' && $b->dates->contains('2026-12-22')))->toBeTrue();

    app(RequestLeave::class)->cancel(persona('jamie'), $leave->id);
    expect($leave->refresh()->status->value)->toBe('cancelled');
});

it('refuses overlapping leave and dates with no working days', function () {
    $request = app(RequestLeave::class);

    expect(fn () => $request->handle(persona('jamie'), ['leave_type' => 'annual', 'start_date' => '2026-11-07', 'end_date' => '2026-11-08']))
        ->toThrow(ValidationException::class, 'Those dates are all weekends or bank holidays.');

    $request->handle(persona('jamie'), ['leave_type' => 'annual', 'start_date' => '2026-11-16', 'end_date' => '2026-11-17']);
    expect(fn () => $request->handle(persona('jamie'), ['leave_type' => 'annual', 'start_date' => '2026-11-17', 'end_date' => '2026-11-18']))
        ->toThrow(ValidationException::class);
});

it('makes a call sheet PDF for the producer and the crew booked that day only', function () {
    $url = route('call-sheets.show', ['EV-26114', '2026-10-23']);

    $this->actingAs(persona('hannah'))->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs(persona('kofi'))->get($url)->assertOk();
    $this->actingAs(persona('marcus'))->get($url)->assertForbidden();
    $this->actingAs(persona('hannah'))->get(route('call-sheets.show', ['EV-26114', '2026-12-25']))->assertNotFound();

    $data = app(CallSheet::class)->data(7, '2026-10-23');
    expect($data['crew'])->not->toBeEmpty()
        ->and($data['firstCall'])->toBe('06:30')
        ->and(collect($data['crew'])->pluck('name'))->toContain('Kofi Mensah')
        ->and($data['venue']['name'])->not->toBeEmpty();
});

it('emails the call sheet to everyone booked that day, with the PDF attached', function () {
    Notification::fake();

    $sent = app(CallSheet::class)->send(persona('hannah'), 7, '2026-10-23');
    $expected = Assignment::query()->where('event_id', 7)->whereIn('status', ['confirmed', 'first-hold', 'second-hold'])
        ->whereDate('start_date', '<=', '2026-10-23')->whereDate('end_date', '>=', '2026-10-23')->distinct()->count('person_id');

    expect($sent)->toBe($expected);
    Notification::assertSentTo(Person::query()->findOrFail(26), CallSheetIssued::class, function (CallSheetIssued $n, array $channels, object $notifiable) {
        $mail = $n->toMail($notifiable);

        return in_array('mail', $channels, true) && $mail->rawAttachments[0]['name'] === 'EV-26114 call sheet 2026-10-23.pdf';
    });
    $this->assertDatabaseHas('activity_log', ['event' => 'call-sheet-sent']);
});

it('offers booking straight from an availability search started on a crew call', function () {
    Livewire::actingAs(persona('hannah'))->test(Availability::class)
        ->set('role', 'AV Technician')->set('from', '2026-11-09')->set('to', '2026-11-13')->set('call', 25)
        ->assertSee('Book on EV-26117');

    Livewire::actingAs(persona('marcus'))->test(Availability::class)
        ->set('role', 'AV Technician')->set('from', '2026-11-09')->set('to', '2026-11-13')->set('call', 25)
        ->assertDontSee('Book on EV-26117');
});

it('sends producers a Monday digest of clashes and open crew calls, and nothing to people with nothing to do', function () {
    app(SendWeeklyRotaDigest::class)->handle($this->workspace());

    // Hannah has Kofi's clash on Northwind and open calls on Lisbon.
    $hannah = Person::query()->findOrFail(4)->notifications()->latest()->first();
    expect($hannah->data['title'])->toBe('Rota this week')->and($hannah->data['body'])->toContain('1 clash');

    // Jamie is neither a producer nor a head of department.
    expect(Person::query()->findOrFail(10)->notifications()->where('data->title', 'Rota this week')->exists())->toBeFalse();
});

it('puts the demo bookings back on refresh', function () {
    Assignment::query()->whereKey(29)->update(['status' => 'released']);
    Unavailability::query()->create(['person_id' => 26, 'start_date' => '2026-12-01', 'end_date' => '2026-12-02']);

    $this->artisan('hive:demo-refresh')->assertSuccessful();

    expect(Assignment::query()->findOrFail(29)->status->value)->toBe('confirmed')
        ->and(Unavailability::query()->where('person_id', 26)->count())->toBe(collect(json_decode(file_get_contents(database_path('demo/unavailability.json')), true))->where('person_id', 26)->count());
});
