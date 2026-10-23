<?php

use App\Actions\Approvals\DelegateApprovals;
use App\Actions\People\DecideLeaveRequest;
use App\Actions\People\RequestLeave;
use App\Actions\Time\AddTimesheetEntry;
use App\Actions\Time\ApproveTimesheetEntry;
use App\Actions\Time\LockPeriod;
use App\Actions\Time\ReturnTimesheetEntry;
use App\Actions\Time\SubmitTimesheet;
use App\Demo\Workspace;
use App\Enums\ApprovalStatus;
use App\Enums\TimesheetStatus;
use App\Jobs\RemindApproversOfPendingItems;
use App\Jobs\RemindMissingTimesheets;
use App\Livewire\Time\Approvals;
use App\Livewire\Time\PeriodLocks;
use App\Livewire\Time\Timesheets;
use App\Models\Approval;
use App\Models\CostEntry;
use App\Models\LeaveRequest;
use App\Models\PeriodLock;
use App\Models\Person;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Approvals\ApprovalRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

function crewActual(int $eventId): int
{
    return app(Workspace::class)->budgetLines($eventId)->where('category', 'crew')->sum(fn ($l) => $l['actual']->minor);
}

it('takes a freelancer’s week across two events to Approved, each producer approving their part, with crew cost as Actual on both (phase 4 done)', function () {
    $kofi = persona('kofi');
    $northwindBefore = crewActual(7);
    $arclightBefore = crewActual(8);

    // Kofi's draft week of 19 Oct has his Northwind days; he adds the Arclight launch and submits.
    Livewire::actingAs($kofi)->test(Timesheets::class)
        ->set('week', '2026-10-19')
        ->set('date', '2026-10-23')->set('work', 'event:8')->set('start_time', '16:00')->set('end_time', '23:30')->set('break_minutes', 30)
        ->call('addEntry')
        ->assertHasNoErrors();

    $sheet = Timesheet::query()->where('person_id', 26)->whereDate('week_start', '2026-10-19')->firstOrFail();
    $events = $sheet->entries()->pluck('event_id')->unique()->sort()->values()->all();
    expect($events)->toBe([7, 8]);

    Livewire::actingAs($kofi)->test(Timesheets::class)->set('week', '2026-10-19')->call('submit', $sheet->id);
    expect($sheet->refresh()->status)->toBe(TimesheetStatus::Submitted);

    // Each entry went to its event's producer.
    $approvers = Approval::query()->where('approvable_type', TimesheetEntry::class)
        ->whereIn('approvable_id', $sheet->entries()->pluck('id'))->where('status', 'pending')->get()
        ->mapWithKeys(fn ($a) => [$a->approvable_id => $a->approver_id]);
    expect($approvers->unique()->sort()->values()->all())->toBe([4, 5]);

    // Hannah approves Northwind; the week is still waiting for Marcus.
    $northwindIds = $sheet->entries()->where('event_id', 7)->pluck('id')->all();
    Livewire::actingAs(persona('hannah'))->test(Approvals::class)->set('tab', 'timesheets')->call('approveMany', $northwindIds);
    expect($sheet->refresh()->status)->toBe(TimesheetStatus::Submitted);

    // Marcus can't approve Hannah's event, only his own.
    expect(fn () => app(ApproveTimesheetEntry::class)->handle(persona('marcus'), $northwindIds[0]))->toThrow(AuthorizationException::class);

    $arclightIds = $sheet->entries()->where('event_id', 8)->pluck('id')->all();
    Livewire::actingAs(persona('marcus'))->test(Approvals::class)->set('tab', 'timesheets')->call('approveMany', $arclightIds);

    expect($sheet->refresh()->status)->toBe(TimesheetStatus::Approved);

    // Crew cost is Actual on both events, at Kofi's rate on each date.
    $rate = $this->workspace()->rateBook(26)->rateOn('2026-10-23')->minor;
    expect(crewActual(7) - $northwindBefore)->toBe($rate * count($northwindIds))
        ->and(crewActual(8) - $arclightBefore)->toBe($rate * count($arclightIds))
        ->and(CostEntry::query()->where('source_type', 'timesheet-entry')->whereIn('source_id', [...$northwindIds, ...$arclightIds])->where('kind', 'actual')->count())->toBe(count($northwindIds) + count($arclightIds));

    expect(Person::query()->findOrFail(26)->notifications()->latest()->first()->data['title'])->toBe('Timesheet approved');
    expect(Activity::query()->where('log_name', 'time')->whereIn('event', ['submitted', 'approved'])->count())->toBeGreaterThanOrEqual(1 + count($northwindIds) + count($arclightIds));
});

it('returns an entry with a comment, and the resubmitted entry goes back for approval', function () {
    $entry = TimesheetEntry::query()->findOrFail(32);

    app(ReturnTimesheetEntry::class)->handle(persona('hannah'), 32, 'Finish time looks late; you were called until 20:00.');

    expect($entry->refresh()->status->value)->toBe('returned')
        ->and($entry->timesheet->status)->toBe(TimesheetStatus::Returned)
        ->and(Approval::query()->where('approvable_type', TimesheetEntry::class)->where('approvable_id', 32)->where('status', 'rejected')->exists())->toBeTrue();

    // Kofi fixes it and resubmits.
    app(AddTimesheetEntry::class)->update(persona('kofi'), 32, ['date' => $entry->date->toDateString(), 'work' => 'event:'.$entry->event_id, 'start_time' => '07:30', 'end_time' => '20:00', 'break_minutes' => 45]);
    app(SubmitTimesheet::class)->handle(persona('kofi'), $entry->timesheet_id);

    expect($entry->refresh()->status->value)->toBe('pending')
        ->and(Approval::query()->where('approvable_type', TimesheetEntry::class)->where('approvable_id', 32)->where('status', 'pending')->count())->toBe(1);
});

it('never lets someone approve their own time (rule 5)', function () {
    // Hannah logs internal time; it goes to her line manager, not her.
    $hannah = persona('hannah');
    $entry = app(AddTimesheetEntry::class)->handle($hannah, ['date' => '2026-10-21', 'work' => 'Pitch', 'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => 30]);
    $sheet = Timesheet::query()->where('person_id', 4)->whereDate('week_start', '2026-10-19')->firstOrFail();
    app(SubmitTimesheet::class)->handle($hannah, $sheet->id);

    $approval = Approval::query()->where('approvable_type', TimesheetEntry::class)->where('approvable_id', $entry['id'])->firstOrFail();
    expect($approval->approver_id)->not->toBe(4)->and($approval->approver_id)->toBe($this->workspace()->person(4)['line_manager_id']);

    expect(fn () => app(ApproveTimesheetEntry::class)->handle($hannah, $entry['id']))->toThrow(AuthorizationException::class);
});

it('rejects changes to time in a locked period, and logs an unlock with its reason (rule 9)', function () {
    $add = fn () => app(AddTimesheetEntry::class)->handle(persona('kofi'), ['date' => '2026-09-23', 'work' => 'Office', 'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => 30]);

    expect($add)->toThrow(ValidationException::class, 'is locked after payroll');

    $september = PeriodLock::query()->whereDate('period_start', '2026-09-01')->firstOrFail();

    Livewire::actingAs(persona('grace'))->test(PeriodLocks::class)
        ->call('startUnlock', $september->id)
        ->set('unlockReason', '')
        ->call('unlock')
        ->assertHasErrors('reason')
        ->set('unlockReason', 'Kofi’s Office day on 23 Sep was missed; payroll will adjust.')
        ->call('unlock')
        ->assertHasNoErrors();

    expect($add())->toBeArray();
    $this->assertDatabaseHas('activity_log', ['event' => 'period-unlocked', 'causer_id' => persona('grace')->id]);
});

it('locks a period, turning fully approved weeks inside it to Locked', function () {
    // As if payroll for September had just run: no lock, weeks approved.
    PeriodLock::query()->whereDate('period_start', '2026-09-01')->delete();
    Timesheet::query()->where('status', 'locked')->whereDate('week_start', '>=', '2026-09-01')->update(['status' => 'approved', 'locked_at' => null]);
    $this->workspace()->forget();

    $result = app(LockPeriod::class)->lock(persona('grace'), ['period_start' => '2026-09-01', 'period_end' => '2026-09-30']);

    expect($result['locked_weeks'])->toBeGreaterThan(0)
        ->and(Timesheet::query()->where('status', 'locked')->count())->toBeGreaterThanOrEqual($result['locked_weeks']);

    expect(fn () => app(LockPeriod::class)->lock(persona('grace'), ['period_start' => '2026-09-15', 'period_end' => '2026-10-15']))->toThrow(ValidationException::class);
    expect(fn () => app(LockPeriod::class)->lock(persona('hannah'), ['period_start' => '2026-10-01', 'period_end' => '2026-10-10']))->toThrow(HttpException::class);
});

it('lets an approver hand their approvals to a colleague while away', function () {
    // Marcus covers Hannah's approvals; her pending Northwind entries show in his inbox.
    app(DelegateApprovals::class)->handle(persona('hannah'), ['delegate_id' => 5, 'starts_on' => '2026-10-23', 'ends_on' => '2026-10-30', 'reason' => 'Annual leave']);

    Livewire::actingAs(persona('marcus'))->test(Approvals::class)
        ->assertSee('You’re covering approvals for Hannah Clarke')
        ->set('tab', 'timesheets')
        ->assertSee('Covering for Hannah Clarke');

    app(ApproveTimesheetEntry::class)->handle(persona('marcus'), 32);
    expect(TimesheetEntry::query()->findOrFail(32)->status->value)->toBe('approved')
        ->and(Approval::query()->where('approvable_id', 32)->where('status', 'approved')->firstOrFail()->decided_by)->toBe(5);
});

it('refuses cover from yourself or someone who doesn’t approve', function () {
    $delegate = app(DelegateApprovals::class);

    expect(fn () => $delegate->handle(persona('hannah'), ['delegate_id' => 4, 'starts_on' => '2026-10-23', 'ends_on' => '2026-10-24']))->toThrow(ValidationException::class);
    // Jamie is an employee without approval rights.
    expect(fn () => $delegate->handle(persona('hannah'), ['delegate_id' => 10, 'starts_on' => '2026-10-23', 'ends_on' => '2026-10-24']))->toThrow(ValidationException::class);
});

it('settles any-of leave approval with the first decision', function () {
    $leave = app(RequestLeave::class)->handle(persona('jamie'), ['leave_type' => 'annual', 'start_date' => '2026-11-16', 'end_date' => '2026-11-17']);
    $open = Approval::query()->where('approvable_type', LeaveRequest::class)->where('approvable_id', $leave->id)->get();
    expect($open->count())->toBeGreaterThan(1)->and($open->pluck('status')->unique()->all())->toBe([ApprovalStatus::Pending]);

    app(DecideLeaveRequest::class)->handle(persona('leah'), $leave->id, true);

    expect($leave->refresh()->status->value)->toBe('approved')
        ->and(Approval::query()->where('approvable_type', LeaveRequest::class)->where('approvable_id', $leave->id)->where('status', 'pending')->count())->toBe(0);
});

it('runs sequential rules one step at a time', function () {
    // A two-step rule: Hannah's line manager, then the operations director.
    $rule = new class implements ApprovalRule
    {
        public function mode(): string
        {
            return self::SEQUENTIAL;
        }

        public function label(): string
        {
            return 'Test';
        }

        public function requester(Model $approvable): ?int
        {
            return 10;
        }

        public function steps(Model $approvable): array
        {
            return [[4], [1]];
        }
    };
    app()->instance($rule::class, $rule);
    config(['hive.approvals.rules' => [LeaveRequest::class => $rule::class]]);

    $leave = LeaveRequest::query()->create(['person_id' => 10, 'leave_type' => 'annual', 'start_date' => '2026-12-07', 'end_date' => '2026-12-07', 'days' => 1, 'status' => 'pending']);
    $engine = app(ApprovalEngine::class);
    $engine->request($leave);

    expect($engine->decidableBy(1, $leave))->toBeNull()
        ->and($engine->decide(4, $leave, true))->toBe(ApprovalStatus::Pending)
        ->and($engine->decidableBy(1, $leave))->not->toBeNull()
        ->and($engine->decide(1, $leave, true))->toBe(ApprovalStatus::Approved);
});

it('reminds people booked this week whose timesheet is missing or a draft', function () {
    app(RemindMissingTimesheets::class)->handle($this->workspace());

    // Kofi's week of 19 Oct is a draft.
    expect(Person::query()->findOrFail(26)->notifications()->latest()->first()->data['title'])->toBe('Timesheet for this week');
    // Arjun (31) is booked on Northwind and has no timesheet at all.
    expect(Person::query()->findOrFail(31)->notifications()->latest()->first()->data['body'])->toStartWith('You haven’t started');
});

it('reminds approvers of items waiting more than three days', function () {
    $this->travelTo('2026-10-26 09:00');
    app(RemindApproversOfPendingItems::class)->handle($this->workspace());

    $notice = Person::query()->findOrFail(4)->notifications()->latest()->first();
    expect($notice->data['title'])->toEndWith('approvals are waiting')
        ->and($notice->data['body'])->toBe('The oldest has waited since 14 Oct 2026.');
});
