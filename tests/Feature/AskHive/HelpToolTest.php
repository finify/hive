<?php

use App\Actions\Ai\AskHive;
use App\Demo\Insights;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Services\AskHive\Tools;
use Tests\Feature\AskHive\FakeGateway;

/**
 * Ask Hive explains Hive itself from the staff guide (resources/help), and
 * only from the guides the user's role is shown at /help.
 */
function helpTools(string $who): Tools
{
    $workspace = app(Workspace::class);
    $viewer = new Viewer(persona($who), $workspace);

    return new Tools($workspace, $viewer, new Insights($workspace, $viewer));
}

it('finds the guide that answers a how-to question, in full, with related guides', function () {
    $r = helpTools('jamie')->getHelp('How do I submit my timesheet?');

    expect($r['guide']['key'])->toBe('timesheets')
        ->and($r['guide']['url'])->toBe(route('help.show', 'timesheets'))
        ->and($r['guide']['text'])->toContain('Submit')
        ->and($r['related'])->not->toBeEmpty();
});

it('explains terms and sections', function (string $question, string $guide) {
    expect(helpTools('hannah')->getHelp($question)['guide']['key'])->toBe($guide);
})->with([
    ['What does first hold mean?', 'crew-calls'],
    ['How do I resolve a clash?', 'clashes'],
    ['What is TOIL?', 'leave'],
    ['How do I raise a purchase order?', 'purchase-orders'],
]);

it('reads a guide by key and lists the guides with no topic', function () {
    $tools = helpTools('jamie');

    expect($tools->getHelp(key: 'leave')['guide']['title'])->toBe('Leave')
        ->and(collect($tools->getHelp()['guides'])->pluck('key'))->toContain('getting-started', 'leave', 'timesheets');
});

it('only uses the guides the user’s role is shown', function () {
    $tools = helpTools('kofi');

    expect(collect($tools->getHelp()['guides'])->pluck('key'))->not->toContain('budgets', 'purchase-orders', 'users')
        ->and($tools->getHelp(key: 'budgets')['guide'])->toBeNull()
        ->and($tools->getHelp('How are budget versions approved?')['guide']['key'] ?? null)->not->toBe('budgets');
});

it('answers how-to questions from the guide without a model', function () {
    $request = app(AskHive::class)->handle(persona('jamie'), 'How do I submit my timesheet?');

    expect($request->response)->toContain('Choose Submit at the end of the week')
        ->and($request->response)->not->toContain('](')
        ->and($request->answer['sources'])->toBe([['label' => 'Guide: Timesheets', 'href' => route('help.show', 'timesheets')]])
        ->and($request->answer['tools'])->toBe(['get_help']);
});

it('links the guide Claude answered from', function () {
    $fake = claude(new FakeGateway(
        fn () => FakeGateway::useTools(['get_help' => ['topic' => 'what is a first hold']]),
        fn () => FakeGateway::say('A first hold reserves someone for the dates before they are confirmed.'),
    ));

    $request = app(AskHive::class)->handle(persona('hannah'), 'What is a first hold?');

    expect($fake->requests[0]['system'])->toContain('call get_help')
        ->and($request->answer['sources'])->toContain(['label' => 'Guide: Crew calls and booking', 'href' => route('help.show', 'crew-calls')]);
});
