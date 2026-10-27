<?php

use App\Actions\Ai\AskHive;
use App\Actions\Ai\ConfirmProposal;
use App\Actions\People\RequestLeave;
use App\Actions\Time\AddTimesheetEntry;
use App\Demo\Insights;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Livewire\AskHive as AskHivePanel;
use App\Models\AiRequest;
use App\Models\Assignment;
use App\Models\ChecklistItem;
use App\Models\Expense;
use App\Models\LeaveRequest;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Models\Unavailability;
use App\Services\AskHive\Proposals;
use App\Services\AskHive\Tools;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\AskHive\FakeGateway;

/**
 * Ask Hive proposes changes; people confirm them. These prove the model
 * never changes anything itself, that a proposal is checked by the real
 * Action without keeping anything, and that only the user's own Confirm
 * makes the change, through the same Action as the screen.
 */
function toolsFor(string $who): Tools
{
    $workspace = app(Workspace::class);
    $viewer = new Viewer(persona($who), $workspace);

    return new Tools($workspace, $viewer, new Insights($workspace, $viewer));
}

it('offers each role only the changes it can make', function () {
    $names = fn (string $who) => array_column(toolsFor($who)->definitionsFor(), 'name');

    expect($names('kofi'))->toContain('propose_respond_to_booking', 'propose_mark_unavailable', 'propose_add_timesheet_entry')
        ->not->toContain('propose_book_person', 'propose_decide_leave', 'propose_approve_timesheet_entries', 'propose_request_leave')
        ->and($names('hannah'))->toContain('propose_book_person', 'propose_release_booking', 'propose_approve_timesheet_entries', 'propose_add_task')
        ->not->toContain('propose_mark_unavailable')
        ->and($names('jamie'))->toContain('propose_request_leave', 'propose_cancel_leave')
        ->not->toContain('propose_book_person', 'propose_decide_leave')
        ->and($names('leah'))->toContain('propose_decide_leave', 'propose_tick_checklist_item');
});

it('checks a proposal with the real Action and keeps nothing: no record, no log, no notice, no mail', function () {
    Mail::fake();
    $leave = LeaveRequest::query()->count();
    $activity = Activity::query()->count();
    $notices = DB::table('notifications')->count();

    $request = app(AskHive::class)->handle(persona('jamie'), 'Book annual leave from 16 Nov to 18 Nov');
    $proposal = $request->proposals()[0];

    expect($request->status)->toBe('answered')
        ->and($request->response)->toContain('nothing has changed yet')
        ->and($proposal)->toMatchArray(['operation' => 'request_leave', 'status' => 'pending'])
        ->and($proposal['input'])->toBe(['leave_type' => 'annual', 'start_date' => '2026-11-16', 'end_date' => '2026-11-18'])
        ->and($proposal['summary'])->toBe('Ask for Annual leave, 16–18 Nov 2026 (3 days)')
        ->and(LeaveRequest::query()->count())->toBe($leave)
        ->and(Activity::query()->count())->toBe($activity)
        ->and(DB::table('notifications')->count())->toBe($notices);

    Mail::assertNothingOutgoing();
});

it('makes the change only when the user confirms it, through the Action, and logs both', function () {
    $jamie = persona('jamie');
    $request = app(AskHive::class)->handle($jamie, 'Book annual leave from 16 Nov to 18 Nov');
    $id = $request->proposals()[0]['id'];

    Livewire::actingAs($jamie)->test(AskHivePanel::class)
        ->set('requestIds', [$request->id])
        ->assertSee('Proposed change')
        ->assertSee('Ask for Annual leave, 16–18 Nov 2026 (3 days)')
        ->call('confirm', $request->id, $id)
        ->assertDispatched('toast', message: 'Leave requested: 3 days. Your manager has been told.', type: 'success')
        ->assertDontSee('Proposed change');

    $leave = LeaveRequest::query()->where('person_id', $jamie->person_id)->latest('id')->firstOrFail();

    expect($leave->start_date->toDateString())->toBe('2026-11-16')
        ->and($leave->status->value)->toBe('pending')
        ->and($request->fresh()->proposal($id))->toMatchArray(['status' => 'done', 'message' => 'Leave requested: 3 days. Your manager has been told.'])
        ->and(Activity::query()->where('log_name', 'people')->where('causer_id', $jamie->id)->where('event', 'leave-requested')->exists())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'ai')->where('event', 'proposal-done')->where('causer_id', $jamie->id)->exists())->toBeTrue();
});

it('confirms a proposal once: a second confirm, or a cancel afterwards, is refused', function () {
    $jamie = persona('jamie');
    $request = app(AskHive::class)->handle($jamie, 'Book annual leave from 16 Nov to 18 Nov');
    $id = $request->proposals()[0]['id'];

    app(ConfirmProposal::class)->handle($jamie, $request->id, $id);

    expect(fn () => app(ConfirmProposal::class)->handle($jamie, $request->id, $id))->toThrow(ValidationException::class, 'already been dealt with')
        ->and(fn () => app(ConfirmProposal::class)->cancel($jamie, $request->id, $id))->toThrow(ValidationException::class)
        ->and(LeaveRequest::query()->where('person_id', $jamie->person_id)->whereDate('start_date', '2026-11-16')->count())->toBe(1);
});

it('cancels a proposal without changing anything', function () {
    $jamie = persona('jamie');
    $request = app(AskHive::class)->handle($jamie, 'Book annual leave from 16 Nov to 18 Nov');
    $id = $request->proposals()[0]['id'];
    $leave = LeaveRequest::query()->count();

    Livewire::actingAs($jamie)->test(AskHivePanel::class)
        ->set('requestIds', [$request->id])
        ->call('cancelProposal', $request->id, $id)
        ->assertSee('Cancelled. Nothing changed.');

    expect(LeaveRequest::query()->count())->toBe($leave)
        ->and(fn () => app(ConfirmProposal::class)->handle($jamie, $request->id, $id))->toThrow(ValidationException::class);
});

it('never lets someone else confirm a user’s proposal', function () {
    $request = app(AskHive::class)->handle(persona('jamie'), 'Book annual leave from 16 Nov to 18 Nov');
    $id = $request->proposals()[0]['id'];

    expect(fn () => app(ConfirmProposal::class)->handle(persona('leah'), $request->id, $id))->toThrow(ValidationException::class, 'isn’t yours')
        ->and($request->fresh()->proposal($id)['status'])->toBe('pending');
});

it('lets a proposal expire', function () {
    $jamie = persona('jamie');
    $request = app(AskHive::class)->handle($jamie, 'Book annual leave from 16 Nov to 18 Nov');
    $id = $request->proposals()[0]['id'];

    $this->travel(31)->minutes();

    expect(fn () => app(ConfirmProposal::class)->handle($jamie, $request->id, $id))->toThrow(ValidationException::class, 'expired')
        ->and($request->fresh()->proposal($id)['status'])->toBe('expired');
});

it('records the Action’s refusal when the world changed before the user confirmed', function () {
    $jamie = persona('jamie');
    $request = app(AskHive::class)->handle($jamie, 'Book annual leave from 16 Nov to 18 Nov');
    $id = $request->proposals()[0]['id'];

    // The same leave booked from the Leave page in the meantime.
    app(RequestLeave::class)->handle($jamie, ['leave_type' => 'annual', 'start_date' => '2026-11-16', 'end_date' => '2026-11-18', 'reason' => null]);

    $result = app(ConfirmProposal::class)->handle($jamie, $request->id, $id);

    expect($result['ok'])->toBeFalse()
        ->and($request->fresh()->proposal($id)['status'])->toBe('failed')
        ->and(LeaveRequest::query()->where('person_id', $jamie->person_id)->whereDate('start_date', '2026-11-16')->count())->toBe(1)
        ->and(Activity::query()->where('log_name', 'ai')->where('event', 'proposal-failed')->exists())->toBeTrue();
});

it('lets a freelancer accept a booking request in conversation', function () {
    $kofi = persona('kofi');
    $assignment = Assignment::query()->where('person_id', $kofi->person_id)->whereNull('responded_at')->where('status', '!=', 'released')->firstOrFail();
    $code = app(Workspace::class)->event($assignment->event_id)['code'];

    $request = app(AskHive::class)->handle($kofi, "Accept the booking on {$code}");
    $proposal = $request->proposals()[0];

    expect($proposal['summary'])->toStartWith("Accept the booking on {$code}")
        ->and($assignment->fresh()->responded_at)->toBeNull();

    $result = app(ConfirmProposal::class)->handle($kofi, $request->id, $proposal['id']);

    expect($result)->toBe(['ok' => true, 'message' => 'Booking accepted. The producer has been told.'])
        ->and($assignment->fresh()->responded_at)->not->toBeNull();
});

it('tells the model when Hive would refuse a change, and proposes nothing', function () {
    claude(new FakeGateway(
        fn () => FakeGateway::useTools(['propose_request_leave' => ['leave_type' => 'annual', 'start_date' => '2026-11-18', 'end_date' => '2026-11-16']]),
        function (array $messages) {
            $result = FakeGateway::results($messages)[0];
            expect($result['proposed'])->toBeFalse()->and($result['error'])->toStartWith('Hive would refuse this change:');

            return FakeGateway::say('Those dates are the wrong way round. Which dates did you mean?');
        },
    ));

    $request = app(AskHive::class)->handle(persona('jamie'), 'Book leave 18 to 16 Nov');

    expect($request->proposals())->toBe([])
        ->and($request->refused)->toBeFalse();
});

it('refuses a change the role can’t make, even if the model asks for it by name', function () {
    claude(new FakeGateway(
        fn () => FakeGateway::useTools(['propose_decide_leave' => ['leave_id' => 2, 'approve' => true]]),
        function (array $messages) {
            expect(FakeGateway::results($messages)[0])->toMatchArray(['allowed' => false]);

            return FakeGateway::say('Your role can’t approve leave.');
        },
    ));

    $request = app(AskHive::class)->handle(persona('kofi'), 'Approve leave 2');

    expect($request->proposals())->toBe([])
        ->and($request->refused)->toBeTrue()
        ->and(LeaveRequest::query()->find(2)->status->value)->toBe('pending');
});

it('lets a producer approve timesheet entries by id, after confirming', function () {
    $hannah = persona('hannah');
    $fake = claude(new FakeGateway(
        fn () => FakeGateway::useTools(['list_pending_timesheet_entries' => []]),
        function (array $messages) {
            $entries = collect(FakeGateway::results($messages)[0]['entries'])->filter(fn ($e) => $e['flags'] === [])->take(2);

            return FakeGateway::useTools(['propose_approve_timesheet_entries' => ['entry_ids' => $entries->pluck('entry_id')->all()]]);
        },
        fn () => FakeGateway::say('I’ve proposed approving the two unflagged entries. Check them and confirm.'),
    ));

    $request = app(AskHive::class)->handle($hannah, 'Approve the unflagged entries');
    $proposal = $request->proposals()[0];
    $ids = $proposal['input']['entry_ids'];

    expect($ids)->toHaveCount(2)
        ->and($proposal['summary'])->toStartWith('Approve 2 timesheet entries:')
        ->and(TimesheetEntry::query()->whereIn('id', $ids)->pluck('status')->map->value->unique()->all())->toBe(['pending'])
        ->and($fake->requests[0]['system'])->toContain('Never say a change is done');

    app(ConfirmProposal::class)->handle($hannah, $request->id, $proposal['id']);

    expect(TimesheetEntry::query()->whereIn('id', $ids)->pluck('status')->map->value->unique()->all())->toBe(['approved']);
});

it('carries the conversation: earlier turns and what happened to their proposals go to the model', function () {
    $jamie = persona('jamie');
    $first = app(AskHive::class)->handle($jamie, 'Book annual leave from 16 Nov to 18 Nov');
    app(ConfirmProposal::class)->handle($jamie, $first->id, $first->proposals()[0]['id']);

    $fake = claude(new FakeGateway(fn () => FakeGateway::say('Yes, it’s waiting for your manager.')));
    $second = app(AskHive::class)->handle($jamie, 'Did that go through?', previousId: $first->id);

    $messages = $fake->requests[0]['messages'];

    expect($second->previous_id)->toBe($first->id)
        ->and($messages)->toHaveCount(3)
        ->and($messages[0])->toBe(['role' => 'user', 'content' => 'Book annual leave from 16 Nov to 18 Nov'])
        ->and($messages[1]['role'])->toBe('assistant')
        ->and($messages[1]['content'])->toContain('[Confirmed and done: Ask for Annual leave, 16–18 Nov 2026 (3 days) — Leave requested')
        ->and($messages[2])->toBe(['role' => 'user', 'content' => 'Did that go through?']);
});

it('never carries another user’s conversation', function () {
    $theirs = app(AskHive::class)->handle(persona('leah'), 'Who has leave waiting for me?');

    $fake = claude(new FakeGateway(fn () => FakeGateway::say('Hello.')));
    $mine = app(AskHive::class)->handle(persona('jamie'), 'And next week?', previousId: $theirs->id);

    expect($mine->previous_id)->toBeNull()
        ->and($fake->requests[0]['messages'])->toHaveCount(1);
});

it('links follow-up questions in the panel', function () {
    $jamie = persona('jamie');

    $panel = Livewire::actingAs($jamie)->test(AskHivePanel::class)
        ->set('question', 'How much leave do I have left?')->call('ask')
        ->set('question', 'And TOIL?')->call('ask');

    [$first, $second] = $panel->get('requestIds');

    expect(AiRequest::query()->find($second)->previous_id)->toBe($first);
});

it('gives the read tools ids the propose tools need', function () {
    $kofi = toolsFor('kofi');
    $hannah = toolsFor('hannah');

    expect($kofi->listMyBookings()['bookings'][0])->toHaveKeys(['assignment_id', 'role', 'waiting_for_your_answer'])
        ->and($kofi->getMyTimesheet('2026-10-19')['entries'][0] ?? ['entry_id' => 0])->toHaveKey('entry_id')
        ->and($hannah->listPendingTimesheetEntries()['entries'][0])->toHaveKey('entry_id')
        ->and($hannah->listCrewCalls('EV-26114')['calls'][0])->toHaveKey('crew_call_id')
        ->and($hannah->getEventCrew('EV-26114')['crew'][0])->toHaveKey('assignment_id')
        ->not->toHaveKey('agreed_rate_minor')
        ->and($hannah->getEventTasks('EV-26114')['tasks'][0])->toHaveKey('task_id');
});

it('keeps the new read tools inside the viewer’s scope', function () {
    $kofi = toolsFor('kofi');

    expect($kofi->searchPeople('a')['allowed'])->toBeFalse()
        ->and($kofi->getPerson(4)['allowed'])->toBeFalse()
        ->and($kofi->getEventCrew('EV-26114')['allowed'])->toBeFalse()
        ->and($kofi->listPurchaseOrders()['allowed'])->toBeFalse()
        ->and($kofi->listSupplierInvoices()['allowed'])->toBeFalse()
        ->and($kofi->runReport('utilisation')['allowed'])->toBeFalse()
        ->and($kofi->searchDirectory('London')['allowed'])->toBeFalse()
        ->and($kofi->getPerson(26)['allowed'])->toBeTrue();

    $person = toolsFor('jamie')->getPerson(4)['person'];

    expect($person)->not->toHaveKeys(['email', 'phone', 'restricted', 'notes', 'day_rates'])
        ->and($person['name'])->toBe('Hannah Clarke');
});

it('checks, then makes, every kind of change Ask Hive can propose', function (string $who, string $operation, Closure $input, Closure $changed) {
    $user = persona($who);
    $proposals = app(Proposals::class);
    $op = $proposals->operation($operation);
    $args = $input();

    $check = $proposals->check($user, $op, $args);
    expect($check['error'] ?? null)->toBeNull()
        ->and($check['summary'])->not->toBe('')
        ->and($changed($args))->toBeFalse();

    $message = $op->perform($user, $args);
    app(Workspace::class)->forget();

    expect($message)->not->toBe('')->and($changed($args))->toBeTrue();
})->with([
    'request leave' => ['jamie', 'request_leave', fn () => ['leave_type' => 'annual', 'start_date' => '2026-12-07', 'end_date' => '2026-12-08'],
        fn () => LeaveRequest::query()->whereDate('start_date', '2026-12-07')->exists()],
    'cancel leave' => ['jamie', 'cancel_leave', function () {
        $leave = app(RequestLeave::class)->handle(persona('jamie'), ['leave_type' => 'annual', 'start_date' => '2026-12-14', 'end_date' => '2026-12-14', 'reason' => null]);

        return ['leave_id' => $leave->id];
    }, fn () => LeaveRequest::query()->whereDate('start_date', '2026-12-14')->first()->status->value === 'cancelled'],
    'add time' => ['jamie', 'add_timesheet_entry', fn () => ['date' => '2026-10-21', 'internal_code' => 'Office', 'start_time' => '09:00', 'end_time' => '17:30', 'break_minutes' => 30],
        fn () => TimesheetEntry::query()->whereDate('date', '2026-10-21')->where('internal_code', 'Office')->exists()],
    'submit timesheet' => ['jamie', 'submit_timesheet', function () {
        app(AddTimesheetEntry::class)->handle(persona('jamie'), ['date' => '2026-10-21', 'work' => 'Office', 'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => 30, 'notes' => null]);

        return ['week' => '2026-10-21'];
    }, fn () => Timesheet::query()->where('person_id', persona('jamie')->person_id)->whereDate('week_start', '2026-10-19')->first()->status->value !== 'draft'],
    'mark unavailable' => ['kofi', 'mark_unavailable', fn () => ['start_date' => '2026-12-01', 'end_date' => '2026-12-02', 'note' => 'Away'],
        fn () => Unavailability::query()->whereDate('start_date', '2026-12-01')->exists()],
    'claim mileage' => ['jamie', 'claim_mileage', fn () => ['date' => '2026-10-20', 'miles' => 42, 'description' => 'Office to Riverside site visit'],
        fn () => Expense::query()->where('description', 'Office to Riverside site visit')->exists()],
    'return an entry' => ['hannah', 'return_timesheet_entry', fn () => ['entry_id' => toolsFor('hannah')->listPendingTimesheetEntries()['entries'][0]['entry_id'], 'comment' => 'Add the break.'],
        fn () => TimesheetEntry::query()->where('return_comment', 'Add the break.')->exists()],
    'decide leave' => ['leah', 'decide_leave', fn () => ['leave_id' => toolsFor('leah')->listLeaveToDecide()['leave'][0]['leave_id'], 'approve' => true],
        fn (array $args) => LeaveRequest::query()->find($args['leave_id'])->status->value === 'approved'],
    'add a task' => ['hannah', 'add_task', fn () => ['event_code' => 'EV-26114', 'title' => 'Confirm lectern mics', 'due_date' => '2026-10-26'],
        fn () => Task::query()->where('title', 'Confirm lectern mics')->exists()],
    'move a task' => ['hannah', 'set_task_status', fn () => ['task_id' => collect(toolsFor('hannah')->getEventTasks('EV-26114')['tasks'])->firstWhere('status', '!=', 'done')['task_id'], 'status' => 'done'],
        fn (array $args) => Task::query()->find($args['task_id'])->status->value === 'done'],
    'release a booking' => ['hannah', 'release_booking', fn () => ['assignment_id' => toolsFor('hannah')->getEventCrew('EV-26114')['crew'][0]['assignment_id']],
        fn (array $args) => Assignment::query()->find($args['assignment_id'])->status->value === 'released'],
    'tick a checklist item' => ['leah', 'tick_checklist_item', fn () => ['item_id' => toolsFor('leah')->listMyChecklistItems()['items'][0]['item_id'], 'done' => true],
        fn (array $args) => ChecklistItem::query()->find($args['item_id'])->completed_at !== null],
]);

it('books someone on a crew call it found free, after confirming', function () {
    $hannah = toolsFor('hannah');
    $workspace = app(Workspace::class);
    $booking = null;

    foreach (collect($hannah->searchEvents()['events'])->where('producer', 'Hannah Clarke') as $event) {
        foreach ($hannah->listCrewCalls($event['code'])['calls'] ?? [] as $call) {
            if ($call['open'] > 0 && ($person = $hannah->findAvailablePeople($call['role'], $call['start_date'], $call['end_date'])['people'][0] ?? null)) {
                $booking = ['crew_call_id' => $call['crew_call_id'], 'person_id' => $person['id'], 'status' => 'first-hold'];
                break 2;
            }
        }
    }

    expect($booking)->not->toBeNull();

    $proposals = app(Proposals::class);
    $op = $proposals->operation('book_person');
    $check = $proposals->check(persona('hannah'), $op, $booking);
    $before = Assignment::query()->where('crew_call_id', $booking['crew_call_id'])->count();

    expect($check['error'] ?? null)->toBeNull()
        ->and($check['summary'])->toContain($workspace->personName($booking['person_id']))
        ->and(Assignment::query()->where('crew_call_id', $booking['crew_call_id'])->count())->toBe($before);

    $op->perform(persona('hannah'), $booking);

    expect(Assignment::query()->where('crew_call_id', $booking['crew_call_id'])->count())->toBe($before + 1);
});

it('answers a follow-up about the leave it just requested, without the model', function () {
    $jamie = persona('jamie');
    $first = app(AskHive::class)->handle($jamie, 'Book annual leave from 16 Nov to 18 Nov');
    app(ConfirmProposal::class)->handle($jamie, $first->id, $first->proposals()[0]['id']);

    $second = app(AskHive::class)->handle($jamie, 'Did my leave request go through?', previousId: $first->id);

    expect($second->response)->toContain('Annual leave, 16–18 Nov 2026 (3 days): waiting for your manager.')
        ->and($first->fresh()->answer['tools'])->toBe([]);
});
