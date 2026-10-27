<?php

use App\Actions\Ai\AskHive;
use App\Demo\Insights;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Livewire\AskHive as AskHivePanel;
use App\Models\AiRequest;
use App\Services\AskHive\Tools;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('refuses a freelancer asking about budgets (phase 7)', function () {
    $answer = app(AskHive::class)->handle(persona('kofi'), 'Which events are at risk this month and why?');

    expect($answer->status)->toBe('answered')
        ->and($answer->refused)->toBeTrue()
        ->and($answer->response)->toContain('You don’t have access to event budgets')
        ->and($answer->response)->not->toContain('£');
});

it('gives the director a cited answer', function () {
    $answer = app(AskHive::class)->handle(persona('priya'), 'Which events are at risk this month and why?');

    expect($answer->refused)->toBeFalse()
        ->and($answer->response)->toContain('EV-26104 Meridian Bank Leadership Forum is at risk')
        ->and($answer->response)->toContain('disputed invoice CUBE-9921')
        ->and(collect($answer->answer['sources'])->pluck('label'))->toContain('EV-26104 budget');
});

it('logs every request with the tools called', function () {
    app(AskHive::class)->handle(persona('hannah'), 'Who’s free for the Lisbon build?');

    $log = AiRequest::query()->firstOrFail();
    expect($log->user_id)->toBe(persona('hannah')->id)
        ->and($log->engine)->toBe('local')
        ->and(collect($log->tools_called)->pluck('tool')->all())->toContain('find_available_people');
});

it('never sends rate fields to the model without rates.view (rule 17)', function () {
    $answer = app(AskHive::class)->handle(persona('hannah'), 'Who’s free for the Lisbon build?');
    expect($answer->response)->not->toContain('a day');

    $finance = app(AskHive::class)->handle(persona('priya'), 'Who’s free for the Lisbon build?');
    expect($finance->response)->toContain('a day');
});

it('never returns restricted fields from any tool', function (string $who) {
    $user = persona($who);
    $viewer = new Viewer($user, app(Workspace::class));
    $tools = new Tools(app(Workspace::class), $viewer, new Insights(app(Workspace::class), $viewer));

    $everything = json_encode([
        $tools->searchEvents(),
        $tools->getEventSummary('EV-26114'),
        $tools->findAvailablePeople('AV Technician', '2026-11-09', '2026-11-13'),
        $tools->listClashes(),
        $tools->getBudgetHealth(),
        $tools->listPendingApprovals(),
        $tools->getLeaveCalendar('2026-09-01', '2026-12-31'),
        $tools->listMyBookings(),
        $tools->listExpiringDocuments(),
    ]);

    expect($everything)->not->toContain('passport')
        ->not->toContain('emergency')
        ->not->toContain('Sick')
        ->not->toContain('Parental')
        ->not->toContain('553018274');

    if (! $user->can('rates.view')) {
        expect($everything)->not->toContain('day_rate')->not->toContain('£400');
    }
})->with(['kofi', 'jamie', 'hannah', 'marcus']);

it('rate limits each user per hour', function () {
    config(['hive.ai.hourly_limit' => 2]);
    $ask = app(AskHive::class);

    $ask->handle(persona('jamie'), 'Who is off next week?');
    $ask->handle(persona('jamie'), 'Who is off next week?');
    $ask->handle(persona('jamie'), 'Who is off next week?');
})->throws(ValidationException::class, 'questions this hour');

it('says when it has no data rather than guessing', function () {
    $answer = app(AskHive::class)->handle(persona('priya'), 'What is the weather in Lisbon?');

    expect($answer->response)->toContain('I don’t have a tool for that question');
});

it('answers in the panel with sources', function () {
    Livewire::actingAs(persona('hannah'))
        ->test(AskHivePanel::class)
        ->call('suggest', 'Who is double-booked this week?')
        ->assertSee('Kofi Mensah on 23 Oct 2026')
        ->assertSee('Clashes');
});

it('tells a line manager the leave type of their own reports', function () {
    $answer = json_encode((new Tools(app(Workspace::class), $v = new Viewer(persona('tomasz'), app(Workspace::class)), new Insights(app(Workspace::class), $v)))->getLeaveCalendar('2026-10-12', '2026-10-13'));

    expect($answer)->toContain('Sick');
});
