<?php

namespace App\Services\AskHive;

use App\Demo\Rows;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Support\DateRange;

/**
 * A deterministic engine for the demo and for tests: it reads the question,
 * picks the tools a model would pick, and writes the answer from their
 * results. It never guesses: with no tool for the question, it says so.
 */
class LocalEngine implements AskHiveEngine
{
    private const ROLES = [
        'av' => 'AV Technician', 'technician' => 'AV Technician', 'disguise' => 'AV Technician',
        'lighting' => 'Lighting Designer', 'stage manager' => 'Stage Manager', 'sound' => 'Sound Engineer',
        'camera' => 'Camera Operator', 'rigger' => 'Rigger', 'registration' => 'Registration Staff',
        'designer' => 'Graphic Designer', 'motion' => 'Motion Designer',
    ];

    public function __construct(private readonly Workspace $workspace) {}

    public function name(): string
    {
        return 'local';
    }

    public function answer(string $question, Viewer $viewer, Tools $tools): Answer
    {
        $q = mb_strtolower($question);

        return match (true) {
            (bool) preg_match('/\bev-?\d{5}\b/', $q) => $this->eventSummary($q, $tools),
            $this->mentions($q, ['risk', 'over budget', 'budget', 'margin', 'trending', 'overspend']) => $this->budgetHealth($tools),
            $this->mentions($q, ['free', 'available', 'availability', 'who can', 'cover']) => $this->whoIsFree($q, $tools),
            $this->mentions($q, ['clash', 'double-book', 'double book', 'overbook']) => $this->clashes($tools),
            $this->mentions($q, ['approv', 'waiting for me', 'waiting for my', 'to sign off']) => $this->approvals($tools),
            $this->mentions($q, ['document', 'insurance', 'expir', 'certificate']) => $this->documents($tools),
            $this->mentions($q, ['booked on', 'my next', 'am i booked', 'my booking', 'next booking', 'day off']) => $this->myBookings($q, $tools),
            $this->mentions($q, ['off next week', 'who is off', 'who’s off', 'on leave', 'holiday', 'away']) => $this->leave($tools),
            default => new Answer([
                'I can only answer from Hive’s own records, and I don’t have a tool for that question.',
                'Try asking about events at risk, who is free for a role, clashes, your approvals, leave or your bookings.',
            ]),
        };
    }

    /**
     * @param  list<string>  $needles
     */
    private function mentions(string $q, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($q, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function budgetHealth(Tools $tools): Answer
    {
        $result = $tools->getBudgetHealth();

        if (! $result['allowed']) {
            return new Answer(
                ['You don’t have access to event budgets, so I can’t say which events are at risk or why.', 'Your producer or the finance team can tell you. I can help with your bookings, timesheets and leave.'],
                tools: $tools->calls(),
                refused: true,
            );
        }

        $month = $this->workspace->today()->format('Y-m');
        $flagged = collect($result['events'])->filter(fn ($e) => in_array($e['health'], ['At risk', 'Watch'], true));

        if ($flagged->isEmpty()) {
            return new Answer(['Every active event you can see is on track: forecasts are within 90% of budget and margins are at or above target.'], tools: $tools->calls());
        }

        $bullets = $flagged->sortBy(fn ($e) => $e['health'] === 'At risk' ? 0 : 1)->map(function ($e) {
            $why = [];
            $why[] = "forecast {$e['forecast']} is {$e['forecast_percent_of_budget']}% of a {$e['budget']} budget";
            $why[] = "margin {$e['margin_percent']}%";

            if ($e['over_budget_lines']) {
                $why[] = 'over budget on '.implode(', ', $e['over_budget_lines']);
            }

            if ($e['open_disputes']) {
                $why[] = 'disputed invoice '.implode(', ', $e['open_disputes']);
            }

            return "{$e['code']} {$e['name']} is ".($e['health'] === 'At risk' ? 'at risk' : 'on watch').': '.implode('; ', $why).'.';
        })->values()->all();

        $thisMonth = $flagged->filter(fn ($e) => str_starts_with((string) $e['start_date'], $month))->count();

        return new Answer(
            [sprintf('%d %s need attention, %d of them running this month:', $flagged->count(), $flagged->count() === 1 ? 'event needs' : 'events', $thisMonth)],
            $bullets,
            $flagged->map(fn ($e) => ['label' => $e['code'].' budget', 'href' => route('events.show', [$e['code'], 'tab' => 'budget'])])->values()->all(),
            $tools->calls(),
        );
    }

    private function whoIsFree(string $q, Tools $tools): Answer
    {
        $role = 'AV Technician';

        foreach (self::ROLES as $needle => $name) {
            if (str_contains($q, $needle)) {
                $role = $name;
                break;
            }
        }

        // "the Lisbon build": take the dates from the event and phase mentioned.
        $range = null;
        $context = null;

        foreach ($this->workspace->events() as $event) {
            if ($event['city'] && $event['start_date'] && str_contains($q, mb_strtolower($event['city'])) && ! in_array($event['stage'], ['cancelled', 'lost', 'closed'], true)) {
                $phase = Rows::of($event['phases'])->first(fn ($p) => str_contains($q, $p['type']))
                    ?? Rows::of($event['phases'])->firstWhere('type', 'build')
                    ?? ['start_date' => $event['start_date'], 'end_date' => $event['end_date'], 'type' => 'live'];
                $range = DateRange::of($phase['start_date'], $phase['end_date']);
                $context = $event;
                break;
            }
        }

        $range ??= DateRange::of($this->workspace->today()->addWeek()->startOfWeek()->toDateString(), $this->workspace->today()->addWeek()->startOfWeek()->addDays(4)->toDateString());

        $search = $context ? $tools->getEventSummary($context['code']) : ['allowed' => false];
        $result = $tools->findAvailablePeople($role, $range->start->toDateString(), $range->end->toDateString());

        if (! $result['allowed']) {
            return new Answer(['Finding available crew is for producers and heads of department, so I can’t search other people’s availability for you.'], tools: $tools->calls(), refused: true);
        }

        if ($result['people'] === []) {
            return new Answer(["Nobody is free as {$role} on {$result['dates']}. Everyone with that role is booked, on leave or unavailable."], tools: $tools->calls());
        }

        $intro = $context && $search['allowed']
            ? "For {$context['code']} {$context['name']} ({$result['dates']}), these {$role}s are free, best match first:"
            : "These {$role}s are free on {$result['dates']}, best match first:";

        $bullets = array_map(fn ($p) => $p['name'].', '.$p['based_in']
            .(isset($p['skill_level']) ? ", skill {$p['skill_level']} of 5" : '')
            .(isset($p['rating']) ? ", rated {$p['rating']}" : '')
            .(isset($p['day_rate']) ? ", {$p['day_rate']} a day" : ''), $result['people']);

        $sources = array_map(fn ($p) => ['label' => $p['name'], 'href' => route('people.show', $p['id'])], $result['people']);

        if ($context) {
            $sources[] = ['label' => $context['code'].' crew', 'href' => route('events.show', [$context['code'], 'tab' => 'crew'])];
        }

        return new Answer([$intro], $bullets, $sources, $tools->calls());
    }

    private function clashes(Tools $tools): Answer
    {
        $result = $tools->listClashes();

        if ($result['clashes'] === []) {
            return new Answer(['Nobody you can see is double-booked.'], tools: $tools->calls());
        }

        return new Answer(
            [count($result['clashes']) === 1 ? 'One clash is open:' : count($result['clashes']).' clashes are open:'],
            array_map(fn ($c) => "{$c['person']} on {$c['dates']}: ".implode(' and ', $c['bookings']).'.', $result['clashes']),
            [['label' => 'Clashes', 'href' => route('clashes')], ['label' => 'Rota', 'href' => route('rota')]],
            $tools->calls(),
        );
    }

    private function approvals(Tools $tools): Answer
    {
        $r = $tools->listPendingApprovals();
        $bullets = [];

        if ($r['timesheet_entries']) {
            $bullets[] = "{$r['timesheet_entries']} timesheet entries (".implode(', ', $r['groups']).')'.($r['flagged'] ? ", {$r['flagged']} flagged for working time" : '').'.';
        }

        if ($r['invoices']) {
            $bullets[] = 'Supplier invoices: '.implode(', ', $r['invoices']).'.';
        }

        if ($r['leave']) {
            $bullets[] = 'Leave requests from '.implode(', ', $r['leave']).'.';
        }

        if ($bullets === []) {
            return new Answer(['Nothing is waiting for your approval.'], tools: $tools->calls());
        }

        return new Answer(['Waiting for you, oldest first on the approvals page:'], $bullets, [['label' => 'Approvals', 'href' => route('approvals')]], $tools->calls());
    }

    private function documents(Tools $tools): Answer
    {
        $r = $tools->listExpiringDocuments();

        if (! $r['allowed']) {
            return new Answer(['Document details are for HR. You can see your own on My work.'], [], [['label' => 'My work', 'href' => route('my-work')]], $tools->calls(), true);
        }

        return new Answer(
            [count($r['documents']).' documents have expired or expire within 30 days:'],
            array_map(fn ($d) => "{$d['person']}: {$d['title']}, ".mb_strtolower($d['status'])." ({$d['expires']}).", array_slice($r['documents'], 0, 8)),
            [['label' => 'Documents', 'href' => route('documents')]],
            $tools->calls(),
        );
    }

    private function myBookings(string $q, Tools $tools): Answer
    {
        $r = $tools->listMyBookings();

        if ($r['bookings'] === []) {
            return new Answer(['You have no upcoming bookings in Hive.'], tools: $tools->calls());
        }

        $next = $r['bookings'][0];
        $paragraphs = ["Your next booking is {$next['code']} {$next['event']} in {$next['city']}, {$next['dates']}."];

        if (str_contains($q, 'day off')) {
            $paragraphs[] = 'Days not covered by these bookings are free as far as Hive knows; mark any you can’t work as unavailable.';
        }

        $pending = array_filter($r['bookings'], fn ($b) => ! $b['answered']);

        if ($pending) {
            $paragraphs[] = count($pending) === 1 ? 'One booking request is waiting for your answer on My work.' : count($pending).' booking requests are waiting for your answer on My work.';
        }

        return new Answer(
            $paragraphs,
            array_map(fn ($b) => "{$b['code']} {$b['event']}, {$b['dates']}, ".str_replace('-', ' ', $b['status']).'.', $r['bookings']),
            [['label' => 'My work', 'href' => route('my-work')]],
            $tools->calls(),
        );
    }

    private function leave(Tools $tools): Answer
    {
        $start = $this->workspace->today()->addWeek()->startOfWeek();
        $r = $tools->getLeaveCalendar($start->toDateString(), $start->addDays(6)->toDateString());

        if (! $r['allowed']) {
            return new Answer(['The team leave calendar isn’t shared with your role.'], tools: $tools->calls(), refused: true);
        }

        if ($r['away'] === []) {
            return new Answer(['Nobody has approved leave next week.'], tools: $tools->calls());
        }

        return new Answer(
            ['Away next week ('.DateRange::of($start->toDateString(), $start->addDays(6)->toDateString())->format().'):'],
            array_map(fn ($a) => "{$a['person']}: {$a['type']}, {$a['dates']}.", $r['away']),
            [['label' => 'Leave', 'href' => route('leave')]],
            $tools->calls(),
        );
    }

    private function eventSummary(string $q, Tools $tools): Answer
    {
        preg_match('/ev-?(\d{5})/', $q, $m);
        $code = 'EV-'.$m[1];
        $r = $tools->getEventSummary($code);

        if (! $r['allowed'] || ! $r['event']) {
            return new Answer(["I can’t find {$code} among the events you can see."], tools: $tools->calls());
        }

        $e = $r['event'];
        $paragraphs = ["{$e['code']} {$e['name']} for {$e['client']} is at {$e['stage']}, {$e['dates']} in {$e['city']}. Producer: {$e['producer']}."];

        if (! empty($e['phases'])) {
            $paragraphs[] = 'Phases: '.$e['phases'].'.';
        }

        $budget = $tools->getBudgetHealth($code);

        if ($budget['allowed'] && $budget['events']) {
            $b = $budget['events'][0];
            $paragraphs[] = "Forecast {$b['forecast']} against {$b['budget']} ({$b['forecast_percent_of_budget']}%), margin {$b['margin_percent']}%, ".mb_strtolower((string) $b['health']).'.';
        }

        return new Answer($paragraphs, [], [['label' => $e['code'], 'href' => route('events.show', $e['code'])]], $tools->calls());
    }
}
