<?php

use App\Actions\Leave\AccrueToil;
use App\Actions\Leave\ManageBankHolidays;
use App\Actions\Leave\RecordAbsence;
use App\Actions\Leave\SetLeaveAllowance;
use App\Actions\People\RequestLeave;
use App\Actions\Time\ApproveTimesheetEntry;
use App\Demo\DemoData;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Enums\LeaveStatus;
use App\Livewire\People\Leave;
use App\Livewire\Time\Timesheets;
use App\Models\LeaveAllowance;
use App\Models\LeaveRequest;
use App\Models\Person;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Models\ToilAccrual;
use App\Services\Leave\LeaveBook;
use App\Support\DateRange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function leaveBook(): LeaveBook
{
    return app(LeaveBook::class);
}

it('works out a balance from the allowance, carry-over, leave taken and booked', function () {
    $jamie = leaveBook()->balance(10, 2026);

    expect($jamie->entitlement())->toBe(25.0)
        ->and($jamie->taken)->toBe(1.0)
        ->and($jamie->booked)->toBe(5.0)
        ->and($jamie->remaining())->toBe(19.0)
        ->and($jamie->toilRemaining())->toBe(1.5);
});

it('counts working days with the bank holidays of the person’s region', function () {
    $stAndrews = DateRange::of('2026-11-30', '2026-12-04');

    Person::query()->whereKey(10)->update(['bank_holiday_region' => 'scotland']);
    expect(leaveBook()->workingDays(10, $stAndrews))->toBe(4)
        ->and(leaveBook()->workingDays(12, $stAndrews))->toBe(5);
});

it('refuses annual leave beyond what is left in the leave year', function () {
    app(RequestLeave::class)->handle(persona('jamie'), ['leave_type' => 'annual', 'start_date' => '2026-11-02', 'end_date' => '2026-11-27']);
})->throws(ValidationException::class, 'you have 19 days of annual leave left to ask for in 2026');

it('counts pending requests against what can still be asked for', function () {
    app(RequestLeave::class)->handle(persona('jamie'), ['leave_type' => 'annual', 'start_date' => '2026-11-02', 'end_date' => '2026-11-20']);

    expect(leaveBook()->balance(10)->pending)->toBe(15.0)
        ->and(leaveBook()->balance(10)->available())->toBe(4.0);

    app(RequestLeave::class)->handle(persona('jamie'), ['leave_type' => 'annual', 'start_date' => '2026-11-23', 'end_date' => '2026-11-27']);
})->throws(ValidationException::class, '4 days of annual leave left');

it('lets people take TOIL only up to what they have earned', function () {
    $leave = app(RequestLeave::class)->handle(persona('jamie'), ['leave_type' => 'toil', 'start_date' => '2026-11-02', 'end_date' => '2026-11-02']);
    expect((float) $leave->days)->toBe(1.0);

    app(RequestLeave::class)->handle(persona('jamie'), ['leave_type' => 'toil', 'start_date' => '2026-11-03', 'end_date' => '2026-11-04']);
})->throws(ValidationException::class, 'you have 0.5 days of time off in lieu to take');

it('earns TOIL when an employee’s weekend time on an event is approved, and only once', function () {
    $jamie = persona('jamie');

    Livewire::actingAs($jamie)->test(Timesheets::class)
        ->set('week', '2026-10-19')
        ->set('date', '2026-10-24')->set('work', 'event:7')->set('start_time', '09:00')->set('end_time', '17:30')->set('break_minutes', 30)
        ->call('addEntry')
        ->assertHasNoErrors();

    $sheet = Timesheet::query()->where('person_id', 10)->whereDate('week_start', '2026-10-19')->firstOrFail();
    Livewire::actingAs($jamie)->test(Timesheets::class)->set('week', '2026-10-19')->call('submit', $sheet->id);
    $entry = TimesheetEntry::query()->where('timesheet_id', $sheet->id)->whereDate('date', '2026-10-24')->firstOrFail();

    app(ApproveTimesheetEntry::class)->handle(persona('hannah'), $entry->id);

    $toil = ToilAccrual::query()->where('timesheet_entry_id', $entry->id)->sole();
    expect((float) $toil->days)->toBe(1.0)
        ->and($toil->reason)->toBe('EV-26114 on a weekend')
        ->and(leaveBook()->balance(10)->toilEarned)->toBe(2.5)
        ->and(app(AccrueToil::class)->forEntry($entry->id))->toBeNull();
});

it('gives freelancers no TOIL: they are paid for the day', function () {
    $entry = TimesheetEntry::query()->whereHas('timesheet.person', fn ($q) => $q->where('employment_type', 'freelancer'))->where('status', 'approved')->whereNotNull('event_id')->firstOrFail();
    $entry->update(['date' => '2026-10-17']);

    expect(app(AccrueToil::class)->forEntry($entry->id))->toBeNull();
});

it('lets HR record sickness, which others see only as Absent', function () {
    $leave = app(RecordAbsence::class)->handle(persona('leah'), ['person_id' => 10, 'leave_type' => 'sick', 'start_date' => '2026-10-21', 'end_date' => '2026-10-22']);

    expect($leave->status)->toBe(LeaveStatus::Approved)
        ->and((float) $leave->days)->toBe(2.0);

    $row = app(Workspace::class)->leaveRequests()->get($leave->id);
    expect((new Viewer(persona('hannah'), app(Workspace::class)))->leaveLabel($row))->toBe('Absent')
        ->and((new Viewer(persona('leah'), app(Workspace::class)))->leaveLabel($row))->toBe('Sick')
        ->and((new Viewer(persona('jamie'), app(Workspace::class)))->leaveLabel($row))->toBe('Sick');
});

it('lets a line manager record absence for their own reports only', function () {
    $record = app(RecordAbsence::class);

    expect($record->mayRecordFor(persona('priya'), Person::query()->findOrFail(12)))->toBeFalse();

    $record->handle(persona('hannah'), ['person_id' => 10, 'leave_type' => 'sick', 'start_date' => '2026-10-21', 'end_date' => '2026-10-21']);
})->throws(AuthorizationException::class);

it('lets HR set allowances within the carry-over limit', function () {
    $set = app(SetLeaveAllowance::class);
    $set->handle(persona('leah'), 10, 2026, ['days_allowed' => 27, 'days_carried_over' => 2.5]);

    expect(leaveBook()->balance(10)->entitlement())->toBe(29.5);

    expect(fn () => $set->handle(persona('leah'), 10, 2026, ['days_allowed' => 27, 'days_carried_over' => 8]))
        ->toThrow(ValidationException::class, 'No more than 5 days can be carried over');
    expect(fn () => $set->handle(persona('jamie'), 10, 2026, ['days_allowed' => 40, 'days_carried_over' => 0]))
        ->toThrow(AuthorizationException::class);
});

it('opens the next leave year carrying over up to the limit, once', function () {
    expect(leaveBook()->rollYear(2026))->toBe(26)
        ->and(leaveBook()->rollYear(2026))->toBe(0);

    $jamie = LeaveAllowance::query()->where('person_id', 10)->where('leave_year', 2027)->sole();
    expect((float) $jamie->days_carried_over)->toBe(5.0)
        ->and((float) $jamie->days_allowed)->toBe(25.0);

    $this->artisan('hive:leave-year', ['year' => 2027])->expectsOutputToContain('Opened 2028')->assertSuccessful();
});

it('lets HR add and remove bank holidays', function () {
    $manage = app(ManageBankHolidays::class);
    $holiday = $manage->add(persona('leah'), ['region' => 'england-and-wales', 'date' => '2026-11-09', 'name' => 'Company day']);

    expect(leaveBook()->workingDays(10, DateRange::of('2026-11-09', '2026-11-13')))->toBe(4);

    expect(fn () => $manage->add(persona('leah'), ['region' => 'england-and-wales', 'date' => '2026-11-09', 'name' => 'Again']))
        ->toThrow(ValidationException::class, 'already has a bank holiday');

    $manage->remove(persona('leah'), $holiday->id);
    expect((new LeaveBook(app(DemoData::class)))->workingDays(10, DateRange::of('2026-11-09', '2026-11-13')))->toBe(5);
});

it('shows an employee their balance and HR everyone’s', function () {
    Livewire::actingAs(persona('jamie'))->test(Leave::class)
        ->assertSee('Annual leave left in 2026')
        ->assertSee('19 days')
        ->assertSee('Time off in lieu')
        ->assertDontSee('Record an absence')
        ->set('tab', 'balances')
        ->assertSet('tab', 'overview');

    Livewire::actingAs(persona('leah'))->test(Leave::class)
        ->assertSee('Record an absence')
        ->set('tab', 'balances')
        ->assertSee('Jamie Ross')
        ->call('editAllowance', 10)
        ->set('allowanceForm.days_allowed', '26')
        ->call('saveAllowance')
        ->assertHasNoErrors();

    expect((float) LeaveAllowance::query()->where('person_id', 10)->where('leave_year', 2026)->value('days_allowed'))->toBe(26.0)
        ->and(LeaveRequest::query()->count())->toBeGreaterThan(0);
});
