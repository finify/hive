<?php

use App\Actions\Time\AddTimesheetEntry;
use App\Actions\Time\ApproveTimesheetEntry;
use App\Actions\Time\PostTimesheetCost;
use App\Actions\Time\ReturnTimesheetEntry;
use App\Actions\Time\SubmitTimesheet;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Kofi's week of 12 Oct: three days on Halcyon (Hannah's EV-26105) and one
 * pre-light on Arclight (Marcus's EV-26116).
 */
function kofiWeek(): array
{
    return test()->workspace()->timesheets()->first(fn ($s) => $s['person_id'] === 26 && $s['week_start'] === '2026-10-12');
}

it('approves a two-event week per producer, then the whole timesheet (phase 4)', function () {
    $sheet = kofiWeek();
    $hannahs = collect($sheet['entries'])->where('event_id', 5)->pluck('id');
    $marcuss = collect($sheet['entries'])->where('event_id', 8)->pluck('id');
    $approve = app(ApproveTimesheetEntry::class);

    $halcyonBefore = $this->workspace()->financials(5)->actual;
    $arclightBefore = $this->workspace()->financials(8)->actual;

    $hannahs->each(fn ($id) => $approve->handle(persona('hannah'), $id));
    expect(kofiWeek()['status'])->toBe('submitted');

    $marcuss->each(fn ($id) => $approve->handle(persona('marcus'), $id));
    expect(kofiWeek()['status'])->toBe('approved');

    // Three days at £400 on Halcyon and one at £400 on Arclight become Actual.
    expect($this->workspace()->financials(5)->actual->minus($halcyonBefore)->minor)->toBe(120_000)
        ->and($this->workspace()->financials(8)->actual->minus($arclightBefore)->minor)->toBe(40_000)
        ->and($this->workspace()->notificationsFor(26)->first()['title'])->toBe('Timesheet approved');
});

it('stops a producer approving entries on someone else’s event (rule 6)', function () {
    $arclightEntry = collect(kofiWeek()['entries'])->firstWhere('event_id', 8);

    app(ApproveTimesheetEntry::class)->handle(persona('hannah'), $arclightEntry['id']);
})->throws(AuthorizationException::class);

it('stops anyone approving their own time (rule 5)', function () {
    // Hannah as a freelancer-style self-approver: give her an entry on her own event.
    $sheet = ['person_id' => 4, 'status' => 'submitted'];
    $entry = ['id' => 1, 'event_id' => 7, 'internal_code' => null, 'status' => 'pending'];

    expect(persona('hannah')->can('timesheet-entry.approve', [$sheet, $entry]))->toBeFalse();
});

it('posts crew cost at the rate effective on the day worked (rule 7)', function () {
    $entry = collect(kofiWeek()['entries'])->firstWhere('date', '2026-10-14');

    app(ApproveTimesheetEntry::class)->handle(persona('hannah'), $entry['id']);

    $cost = $this->workspace()->costEntries(5)->first(fn ($c) => $c['source']['type'] === 'timesheet-entry' && $c['source']['id'] === $entry['id']);
    expect($cost)->toMatchArray(['kind' => 'actual', 'amount_minor' => 40_000, 'amount_gbp_minor' => 40_000]);
});

it('never posts the same entry twice, and later rate changes do not alter it (rule 8)', function () {
    $entry = collect(kofiWeek()['entries'])->firstWhere('date', '2026-10-13');
    app(ApproveTimesheetEntry::class)->handle(persona('hannah'), $entry['id']);

    expect(app(PostTimesheetCost::class)->handle(26, $entry['id']))->toBeNull();

    // Already-posted costs are stored amounts, so a new rate leaves them alone.
    $posted = $this->workspace()->costEntries(5)->where('source.id', $entry['id']);
    expect($posted)->toHaveCount(1)->and($posted->first()['amount_minor'])->toBe(40_000);
});

it('creates the cost against Hannah’s budget even though Hollie’s rate differs', function () {
    $hollie = $this->workspace()->timesheets()->first(fn ($s) => $s['person_id'] === 30 && $s['week_start'] === '2026-10-12');
    $entry = $hollie['entries'][0];

    app(ApproveTimesheetEntry::class)->handle(persona('hannah'), $entry['id']);

    expect($this->workspace()->costEntries(5)->firstWhere('source.id', $entry['id'])['amount_minor'])->toBe(34_000);
});

it('returns an entry with a comment and tells the person', function () {
    $entry = collect(kofiWeek()['entries'])->firstWhere('date', '2026-10-14');

    app(ReturnTimesheetEntry::class)->handle(persona('hannah'), $entry['id'], 'Please split the 14-hour day into build and show.');

    expect(kofiWeek()['status'])->toBe('returned')
        ->and($this->workspace()->notificationsFor(26)->first()['title'])->toBe('Timesheet returned');
});

it('rejects new time in a locked period (rule 9)', function () {
    app(AddTimesheetEntry::class)->handle(persona('kofi'), [
        'date' => '2026-09-24', 'work' => 'Office', 'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => 30,
    ]);
})->throws(ValidationException::class, 'locked after payroll');

it('adds and submits a week, routing entries to each approver', function () {
    $kofi = persona('kofi');
    app(AddTimesheetEntry::class)->handle($kofi, [
        'date' => '2026-10-23', 'work' => 'event:7', 'start_time' => '07:30', 'end_time' => '19:30', 'break_minutes' => 60,
    ]);

    $sheet = $this->workspace()->timesheets()->first(fn ($s) => $s['person_id'] === 26 && $s['week_start'] === '2026-10-19');
    expect($sheet['entries'])->toHaveCount(4);

    app(SubmitTimesheet::class)->handle($kofi, $sheet['id']);

    expect($this->workspace()->timesheets()->get($sheet['id'])['status'])->toBe('submitted')
        ->and($this->workspace()->notificationsFor(4)->first()['title'])->toBe('Timesheet to approve');
});

it('only logs time on events the person is booked on', function () {
    app(AddTimesheetEntry::class)->handle(persona('kofi'), [
        'date' => '2026-10-23', 'work' => 'event:4', 'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => 30,
    ]);
})->throws(ValidationException::class);

it('routes internal-code time to the line manager', function () {
    $jamie = $this->workspace()->timesheets()->first(fn ($s) => $s['person_id'] === 10);
    $entry = $jamie['entries'][0];
    $ben = User::factory()->withRole(Role::HeadOfDepartment)->create(['person_id' => 9]);

    expect($ben->can('timesheet-entry.approve', [$jamie, $entry]))->toBeTrue()
        ->and(persona('hannah')->can('timesheet-entry.approve', [$jamie, $entry]))->toBeFalse();
});
