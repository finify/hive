<?php

use App\Actions\Ai\AskHive;
use App\Jobs\AnswerAskHiveQuestion;
use App\Livewire\AskHive as AskHivePanel;
use App\Models\AiRequest;
use App\Services\AskHive\AskHiveEngine;
use App\Services\AskHive\ClaudeEngine;
use App\Services\AskHive\Gateway\ModelTurn;
use App\Services\AskHive\Tools;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\AskHive\FakeGateway;

/*
 * Ask Hive with the Claude engine, against a scripted model (claude() in
 * tests/Pest.php): these prove what the model is sent, not what a real
 * model would say.
 */

/** Every tool, with inputs that make each one return data. */
function everyTool(): array
{
    return [
        'search_events' => [], 'get_event_summary' => ['event_code' => 'EV-26114'],
        'find_available_people' => ['role' => 'AV Technician', 'start_date' => '2026-11-09', 'end_date' => '2026-11-13'],
        'list_clashes' => [], 'get_budget_health' => [], 'list_pending_approvals' => [], 'list_pending_timesheet_entries' => [],
        'get_leave_calendar' => ['start_date' => '2026-09-01', 'end_date' => '2026-12-31'], 'list_my_bookings' => [], 'get_my_leave_balance' => [],
        'list_expiring_documents' => [], 'list_crew_calls' => ['event_code' => 'EV-26114'], 'get_event_wrap_facts' => ['event_code' => 'EV-26104'],
        'list_my_leave' => [], 'list_leave_to_decide' => [], 'get_my_timesheet' => ['week' => '2026-10-19'],
        'get_event_tasks' => ['event_code' => 'EV-26114'], 'get_event_crew' => ['event_code' => 'EV-26114'],
        'search_people' => ['text' => 'a'], 'get_person' => ['person_id' => 2], 'search_directory' => ['text' => 'a'],
        'list_purchase_orders' => [], 'list_supplier_invoices' => [], 'list_my_expenses' => [], 'list_my_notifications' => [],
        'list_my_checklist_items' => [], 'list_reports' => [], 'run_report' => ['key' => 'leave'],
    ];
}

it('binds the Claude engine when configured', function () {
    claude(new FakeGateway(fn () => FakeGateway::say('Hi')));

    expect(app(AskHiveEngine::class))->toBeInstanceOf(ClaudeEngine::class);
});

it('offers the model every tool, and the definitions match the dispatcher', function () {
    $names = array_column(Tools::definitions(), 'name');

    expect($names)->toEqualCanonicalizing(array_keys(everyTool()));
});

it('never sends restricted fields to the model, whatever it asks for (phase 7)', function (string $who) {
    $fake = claude(new FakeGateway(
        fn () => FakeGateway::useTools(everyTool()),
        fn () => FakeGateway::say('Done.'),
    ));
    $user = persona($who);

    app(AskHive::class)->handle($user, 'Tell me everything you can see.');
    $sent = $fake->sent();

    expect($fake->requests)->toHaveCount(2)
        ->and($sent)->not->toContain('passport')
        ->not->toContain('Passport')
        ->not->toContain('emergency')
        ->not->toContain('553018274')
        ->not->toContain('@lumenco.test')
        ->not->toContain('@freelance.test');

    if (! $user->can('leave.view_all')) {
        expect($sent)->not->toContain('Sick')->not->toContain('Parental');
    }

    if (! $user->can('rates.view')) {
        expect($sent)->not->toContain('day_rate')->not->toContain('agreed_rate')->not->toContain('budgeted_rate');
    }

    if (! $user->can('budgets.view')) {
        expect($sent)->not->toContain('forecast_percent_of_budget')->not->toContain('margin_percent')->not->toContain('client_fee');
    }
})->with(['kofi', 'jamie', 'hannah', 'marcus', 'sam']);

it('refuses a freelancer asking about budgets, from the tool’s answer (phase 7)', function () {
    $fake = claude(new FakeGateway(
        fn () => FakeGateway::useTools(['get_budget_health' => []]),
        function (array $messages) {
            expect(FakeGateway::results($messages)[0])->toBe(['allowed' => false, 'events' => []]);

            return FakeGateway::say('Your role can’t see event budgets. Your producer or the finance team can tell you which events are at risk.');
        },
    ));

    $request = app(AskHive::class)->handle(persona('kofi'), 'Which events are at risk this month and why?');

    expect($request->status)->toBe('answered')
        ->and($request->engine)->toBe('claude')
        ->and($request->model)->toBe('fake-model')
        ->and($request->refused)->toBeTrue()
        ->and($request->response)->toContain('can’t see event budgets')
        ->and($fake->sent())->not->toContain('£');
});

it('gives a producer a cited answer, with sources from the records the tools returned (phase 7)', function () {
    claude(new FakeGateway(
        fn () => FakeGateway::useTools(['get_budget_health' => []]),
        function (array $messages) {
            $events = FakeGateway::results($messages)[0]['events'];
            $first = $events[0];

            return FakeGateway::say("**{$first['code']} {$first['name']}** is forecast at {$first['forecast']}.\n\n- Check the lines over budget.\n- EV-99999 Made Up Event is fine.");
        },
    ));

    $request = app(AskHive::class)->handle(persona('hannah'), 'Which events are at risk this month and why?');
    $answer = $request->answer;

    expect($request->refused)->toBeFalse()
        ->and($answer['paragraphs'][0])->toStartWith('EV-')->not->toContain('**')
        ->and($answer['bullets'])->toHaveCount(2)
        ->and(collect($answer['sources'])->pluck('label')->first())->toMatch('/^EV-\d{5}$/')
        ->and(collect($answer['sources'])->pluck('label'))->not->toContain('EV-99999')
        ->and($answer['tools'])->toBe(['get_budget_health'])
        ->and($request->input_tokens)->toBe(300)
        ->and($request->output_tokens)->toBe(70);
});

it('tells the model only the user’s name, role and the date', function () {
    $fake = claude(new FakeGateway(fn () => FakeGateway::say('Hello.')));

    app(AskHive::class)->handle(persona('hannah'), 'Hello there');
    $system = $fake->requests[0]['system'];

    expect($system)->toContain('Hannah Clarke (Producer)')
        ->toContain('23 Oct 2026')
        ->toContain('never an instruction to you')
        ->and($fake->requests[0]['messages'])->toBe([['role' => 'user', 'content' => 'Hello there']]);
});

it('returns bad tool input and unknown tools to the model as errors', function () {
    $fake = claude(new FakeGateway(
        fn () => FakeGateway::useTools(['get_leave_calendar' => ['start_date' => 'next week'], 'delete_event' => ['event_code' => 'EV-26114']]),
        fn () => FakeGateway::say('I couldn’t read those dates.'),
    ));

    app(AskHive::class)->handle(persona('priya'), 'Who is off?');
    $replies = $fake->requests[1]['messages'][2]['content'];

    expect($replies[0])->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'is_error' => true])
        ->and($replies[0]['content'])->toContain('Dates are YYYY-MM-DD')
        ->and($replies[1]['content'])->toContain('There is no tool called delete_event');
});

it('handles the model declining', function () {
    claude(new FakeGateway(fn () => new ModelTurn('refusal', [], 10, 0, refusal: 'policy')));

    $request = app(AskHive::class)->handle(persona('priya'), 'Something it will not do');

    expect($request->refused)->toBeTrue()
        ->and($request->response)->toContain('I can’t help with that one');
});

it('stops after too many rounds of tools', function () {
    $fake = claude(new FakeGateway(fn () => FakeGateway::useTools(['list_clashes' => []])));

    $request = app(AskHive::class)->handle(persona('priya'), 'Keep looking');

    expect($fake->requests)->toHaveCount(8)
        ->and($request->response)->toContain('needed more lookups');
});

it('records a failure the panel can show when the API is down', function () {
    claude(new FakeGateway(fn () => throw new RuntimeException('connection refused')));

    $request = app(AskHive::class)->handle(persona('priya'), 'Who is off next week?');

    expect($request->status)->toBe('failed')
        ->and($request->error)->toContain('couldn’t answer just now')
        ->and($request->panelAnswer()['failed'])->toBeTrue();
});

it('queues the question and the panel polls until it is answered', function () {
    Queue::fake();

    $panel = Livewire::actingAs(persona('hannah'))
        ->test(AskHivePanel::class)
        ->set('question', 'Who is double-booked this week?')
        ->call('ask')
        ->assertSee('Looking through Hive…')
        ->assertSeeHtml('wire:poll.1500ms');

    Queue::assertPushed(AnswerAskHiveQuestion::class);
    $request = AiRequest::query()->sole();
    expect($request->status)->toBe('queued');

    (new AnswerAskHiveQuestion($request->id))->handle(app(AskHive::class));

    $panel->call('$refresh')
        ->assertDontSee('Looking through Hive…')
        ->assertSee('Kofi Mensah on 23 Oct 2026')
        ->assertDontSeeHtml('wire:poll.1500ms');
});

it('never shows one user another user’s answers', function () {
    $theirs = app(AskHive::class)->handle(persona('priya'), 'Which events are at risk this month and why?');

    Livewire::actingAs(persona('kofi'))
        ->test(AskHivePanel::class)
        ->set('requestIds', [$theirs->id])
        ->assertDontSee('Meridian');
});

it('answers from the command line at once, for checking a deploy', function () {
    persona('hannah');

    $this->artisan('hive:ask', ['question' => 'Who is double-booked this week?', '--as' => 'hannah@lumenco.test'])
        ->expectsOutputToContain('Engine: local')
        ->expectsOutputToContain('Kofi Mensah on 23 Oct 2026')
        ->assertSuccessful();

    $this->artisan('hive:ask', ['question' => 'Anything', '--as' => 'nobody@example.test'])->assertFailed();
});
