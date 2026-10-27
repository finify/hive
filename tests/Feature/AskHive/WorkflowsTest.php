<?php

use App\Actions\Ai\AskHive;
use App\Livewire\AskHive as AskHivePanel;
use App\Livewire\Events\Show;
use App\Models\Assignment;
use App\Services\AskHive\Workflow;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('summarises the timesheets waiting for a producer', function () {
    $request = app(AskHive::class)->handle(persona('hannah'), '', Workflow::TimesheetSummary);

    expect($request->workflow)->toBe('timesheet-summary')
        ->and($request->prompt)->toBe('Summarise the timesheet entries waiting for my approval, and what to check first.')
        ->and($request->response)->toMatch('/\d+ entr(y is|ies are) waiting/')
        ->and($request->response)->toContain('you decide on each entry')
        ->and($request->response)->not->toContain('£')
        ->and(collect($request->tools_called)->pluck('tool')->all())->toBe(['list_pending_timesheet_entries']);
});

it('explains budget risk on an event', function () {
    $request = app(AskHive::class)->handle(persona('hannah'), '', Workflow::BudgetRisk, 'ev-26104');

    expect($request->subject)->toBe('EV-26104')
        ->and($request->response)->toContain('EV-26104 Meridian Bank Leadership Forum is forecast at')
        ->and($request->response)->toContain('CUBE-9921')
        ->and($request->answer['sources'][0]['label'])->toBe('EV-26104 budget');
});

it('drafts a wrap report, with money only for people who may see it', function () {
    $producer = app(AskHive::class)->handle(persona('hannah'), '', Workflow::WrapReport, 'EV-26104');

    expect($producer->response)->toContain('Draft wrap report: EV-26104 Meridian Bank Leadership Forum')
        ->and($producer->response)->toContain('Crew:')
        ->and($producer->response)->toContain('Money: revenue');
});

it('suggests crew for open places without booking anyone', function () {
    $assignments = Assignment::query()->count();
    $request = app(AskHive::class)->handle(persona('hannah'), '', Workflow::CrewSuggestions, 'EV-26114');

    expect($request->response)->toContain('Free for the open places on EV-26114')
        ->and($request->answer['bullets'])->not->toBeEmpty()
        ->and(Assignment::query()->count())->toBe($assignments)
        ->and(collect($request->tools_called)->pluck('tool')->unique()->values()->all())->toBe(['list_crew_calls', 'find_available_people']);
});

it('refuses workflows the user may not run', function (string $who, Workflow $workflow, ?string $subject) {
    app(AskHive::class)->handle(persona($who), '', $workflow, $subject);
})->with([
    'freelancer, budget risk' => ['kofi', Workflow::BudgetRisk, 'EV-26104'],
    'freelancer, timesheets' => ['kofi', Workflow::TimesheetSummary, null],
    'employee, crew' => ['jamie', Workflow::CrewSuggestions, 'EV-26114'],
    'unknown event' => ['priya', Workflow::WrapReport, 'EV-00000'],
])->throws(HttpException::class);

it('runs a workflow from the panel event', function () {
    Livewire::actingAs(persona('hannah'))
        ->test(AskHivePanel::class)
        ->dispatch('ask-hive-workflow', workflow: 'budget-risk', subject: 'EV-26104')
        ->assertSee('Explain the budget risk on EV-26104')
        ->assertSee('EV-26104 budget');
});

it('shows the wrap report button to the producer of a wrapped event', function () {
    Livewire::actingAs(persona('hannah'))
        ->test(Show::class, ['code' => 'EV-26104'])
        ->assertSee('Draft wrap report');
});
