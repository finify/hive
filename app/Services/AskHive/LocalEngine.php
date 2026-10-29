<?php

namespace App\Services\AskHive;

use App\Demo\Rows;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Support\DateRange;
use App\Support\Display;
use Carbon\CarbonImmutable;

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

    public function model(): ?string
    {
        return null;
    }

    public function answer(Question $question, Viewer $viewer, Tools $tools): Answer
    {
        if ($question->workflow) {
            return match ($question->workflow) {
                Workflow::TimesheetSummary => $this->timesheetSummary($tools),
                Workflow::BudgetRisk => $this->budgetRisk((string) $question->subject, $tools),
                Workflow::WrapReport => $this->wrapReport((string) $question->subject, $tools),
                Workflow::CrewSuggestions => $this->crewSuggestions((string) $question->subject, $tools),
            };
        }

        $q = mb_strtolower($question->text);

        return match (true) {
            (bool) preg_match('/\b(accept|decline)\b.*\bev-?\d{5}\b/', $q) => $this->respondToBooking($q, $tools),
            (bool) preg_match('/\b(book|request|take|ask for)\b.*\b(leave|holiday|time off)\b/', $q) => $this->requestLeave($q, $tools),
            (bool) preg_match('/\bev-?\d{5}\b/', $q) => $this->eventSummary($q, $tools),
            (bool) preg_match('/^(how (do|can|should) (i|we|you)|how to|where (do|can|is|are)|what (is|are|does|do) (a |an |the )?[a-z -]+ mean|what (is|are) (a|an|the) |explain|help with)\b|\bwhat does .+ mean\b/', $q) => $this->help($q, $tools),
            $this->mentions($q, ['risk', 'over budget', 'budget', 'margin', 'trending', 'overspend']) => $this->budgetHealth($tools),
            $this->mentions($q, ['free', 'available', 'availability', 'who can', 'cover']) => $this->whoIsFree($q, $tools),
            $this->mentions($q, ['clash', 'double-book', 'double book', 'overbook']) => $this->clashes($tools),
            $this->mentions($q, ['approv', 'waiting for me', 'waiting for my', 'to sign off']) => $this->approvals($tools),
            $this->mentions($q, ['document', 'insurance', 'expir', 'certificate']) => $this->documents($tools),
            $this->mentions($q, ['leave left', 'leave do i have', 'holiday left', 'days left', 'my balance', 'toil']) => $this->myLeave($tools),
            $this->mentions($q, ['my leave', 'leave request', 'go through', 'been approved']) => $this->myLeaveRequests($tools),
            $this->mentions($q, ['booked on', 'my next', 'am i booked', 'my booking', 'next booking', 'day off']) => $this->myBookings($q, $tools),
            $this->mentions($q, ['off next week', 'who is off', 'who’s off', 'on leave', 'holiday', 'away']) => $this->leave($tools),
            default => $this->noTool($tools),
        };
    }

    private function noTool(Tools $tools): Answer
    {
        return new Answer([
            'I can only answer from Hive’s own records, and I don’t have a tool for that question.',
            'Try asking about events at risk, who is free for a role, clashes, your approvals, leave or your bookings, or how to do something in Hive.',
        ], tools: $tools->calls());
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

        $pending = array_filter($r['bookings'], fn ($b) => $b['waiting_for_your_answer']);

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

    /**
     * "Accept the booking on EV-26114": proposes the answer for the user to
     * confirm.
     */
    private function respondToBooking(string $q, Tools $tools): Answer
    {
        preg_match('/\bev-?(\d{5})\b/', $q, $m);
        $accept = str_contains($q, 'accept');
        $r = $tools->run('propose_respond_to_booking', ['event_code' => 'EV-'.$m[1], 'accept' => $accept]);

        return $this->proposed($r, $tools);
    }

    /**
     * "Book annual leave from 2026-11-16 to 2026-11-18" (or "16 Nov to 18
     * Nov"): proposes the request. Without two dates it asks for them.
     */
    private function requestLeave(string $q, Tools $tools): Answer
    {
        preg_match_all('/\b(\d{4}-\d{2}-\d{2}|\d{1,2} (?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*(?: \d{4})?)\b/', $q, $m);
        $today = $this->workspace->today();
        $dates = array_map(function (string $d) use ($today) {
            $date = CarbonImmutable::parse(preg_match('/\d{4}/', $d) ? $d : $d.' '.$today->year);

            return ($date->lt($today) && ! preg_match('/\d{4}/', $d) ? $date->addYear() : $date)->toDateString();
        }, $m[1]);

        if ($dates === []) {
            return new Answer(['Which dates? Say, for example, “book annual leave from 16 Nov to 18 Nov”.'], tools: $tools->calls());
        }

        $type = match (true) {
            str_contains($q, 'toil') || str_contains($q, 'in lieu') => 'toil',
            str_contains($q, 'training') => 'training',
            str_contains($q, 'unpaid') => 'unpaid',
            default => 'annual',
        };

        $r = $tools->run('propose_request_leave', ['leave_type' => $type, 'start_date' => $dates[0], 'end_date' => $dates[1] ?? $dates[0]]);

        return $this->proposed($r, $tools);
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function proposed(array $r, Tools $tools): Answer
    {
        if (($r['allowed'] ?? true) === false) {
            return new Answer(['Your role can’t make that change in Hive.'], tools: $tools->calls(), refused: true);
        }

        if (isset($r['error'])) {
            return new Answer([str_replace('Hive would refuse this change: ', 'Hive wouldn’t accept that: ', (string) $r['error'])], tools: $tools->calls());
        }

        return new Answer(['Here’s the change. Check it and press Confirm to make it; nothing has changed yet.'], tools: $tools->calls(), proposals: $tools->proposals());
    }

    private function myLeaveRequests(Tools $tools): Answer
    {
        $r = $tools->listMyLeave();

        if (! $r['allowed']) {
            return new Answer(['Leave isn’t recorded in Hive for your role.'], tools: $tools->calls(), refused: true);
        }

        $upcoming = array_values(array_filter($r['leave'], fn ($l) => $l['can_cancel'] || $l['status'] === 'pending'));

        if ($upcoming === []) {
            return new Answer(['You have no upcoming leave requested in Hive.'], tools: $tools->calls());
        }

        return new Answer(
            ['Your upcoming leave:'],
            array_map(fn ($l) => "{$l['type']}, {$l['dates']} (".Display::days((float) $l['days']).'): '.($l['status'] === 'pending' ? 'waiting for your manager' : $l['status']).'.', $upcoming),
            [['label' => 'Leave', 'href' => route('leave')]],
            $tools->calls(),
        );
    }

    /**
     * "How do I submit my timesheet?": the most relevant guide's opening and
     * the passage that matches best, with a link to the guide.
     */
    private function help(string $q, Tools $tools): Answer
    {
        $r = $tools->getHelp($q);
        $guide = $r['guide'] ?? null;

        if ($guide === null) {
            return $this->noTool($tools);
        }

        // Words naming the guide itself say nothing about which passage.
        $title = mb_strtolower($guide['title']);
        $words = array_filter(preg_split('/\W+/u', $q) ?: [], fn ($w) => mb_strlen($w) >= 4 && ! str_contains($title, rtrim($w, 's')));
        $blocks = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $guide['text']) ?: []), fn ($b) => $b !== '' && ! str_starts_with($b, '#') && ! str_starts_with($b, '|')));
        $plain = fn (string $b): string => trim((string) preg_replace(['/\[([^\]]+)\]\([^)]*\)/', '/\*\*|__|`/', '/^\s*[-*]\s+/m', '/\s+/'], ['$1', '', '', ' '], $b));
        $scores = array_map(fn ($b) => collect($words)->sum(fn ($w) => substr_count(mb_strtolower($b), $w)), $blocks);
        $i = $scores !== [] && max($scores) > 0 ? (int) array_search(max($scores), $scores, true) : 0;
        // A passage that introduces a list ("choose Resolve:") brings the list.
        $best = $plain($blocks[$i] ?? '').(str_ends_with($blocks[$i] ?? '', ':') && isset($blocks[$i + 1]) ? ' '.$plain($blocks[$i + 1]) : '');
        $paragraphs = array_values(array_unique(array_filter([$plain($blocks[0] ?? ''), $best])));

        return new Answer(
            [...$paragraphs, 'There’s more in the '.$guide['title'].' guide.'],
            sources: [['label' => 'Guide: '.$guide['title'], 'href' => $guide['url']]],
            tools: $tools->calls(),
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

    private function timesheetSummary(Tools $tools): Answer
    {
        $r = $tools->listPendingTimesheetEntries();

        if (! $r['allowed'] || $r['entries'] === []) {
            return new Answer([$r['allowed'] ? 'No timesheet entries are waiting for you.' : 'Approving timesheets isn’t part of your role.'], tools: $tools->calls(), refused: ! $r['allowed']);
        }

        $entries = collect($r['entries']);
        $flagged = $entries->filter(fn ($e) => $e['flags'] !== []);
        $hours = $entries->sum(fn ($e) => (float) ($e['hours'] ?? 0));
        $byEvent = $entries->groupBy('event')->map(fn ($g, $event) => "{$event} (".count($g).' '.(count($g) === 1 ? 'entry' : 'entries').', '.rtrim(rtrim(number_format($g->sum(fn ($e) => (float) ($e['hours'] ?? 0)), 1), '0'), '.').' hours)')->values()->all();

        $paragraphs = [sprintf('%d %s waiting, %s hours in all: %s.', $entries->count(), $entries->count() === 1 ? 'entry is' : 'entries are', rtrim(rtrim(number_format($hours, 1), '0'), '.'), implode('; ', $byEvent))];
        $bullets = $flagged->map(fn ($e) => "{$e['person']}, {$e['date']} ({$e['event']}): ".implode('; ', $e['flags']).'.')->values()->all();
        $paragraphs[] = $flagged->isEmpty()
            ? 'None of them breaks a working-time rule. Check hours against the crew call before you approve; you decide on each entry.'
            : ($flagged->count() === 1 ? 'Check this one first' : "Check these {$flagged->count()} first").' against the crew call; you decide on each entry:';

        return new Answer($paragraphs, $bullets, [['label' => 'Approvals', 'href' => route('approvals')]], $tools->calls());
    }

    private function budgetRisk(string $code, Tools $tools): Answer
    {
        $r = $tools->getBudgetHealth($code);
        $e = $r['events'][0] ?? null;

        if (! $r['allowed'] || $e === null) {
            return new Answer(['You don’t have access to this event’s budget.'], tools: $tools->calls(), refused: true);
        }

        $paragraphs = ["{$e['code']} {$e['name']} is forecast at {$e['forecast']} against a {$e['budget']} budget ({$e['forecast_percent_of_budget']}%), with a margin of {$e['margin_percent']}%. Health: ".mb_strtolower((string) ($e['health'] ?? 'not set')).'.'];
        $bullets = [];

        if ($e['over_budget_lines']) {
            $paragraphs[] = 'The forecast is being pushed up by '.implode(', ', $e['over_budget_lines']).'.';
            $bullets[] = 'Check the open purchase orders and remaining estimates on those lines.';
        }

        if ($e['open_disputes']) {
            $bullets[] = 'Resolve disputed invoice '.implode(', ', $e['open_disputes']).' before reconciling.';
        }

        if ($bullets === []) {
            $paragraphs[] = 'No line is over budget and nothing is in dispute.';
        }

        return new Answer($paragraphs, $bullets, [['label' => $e['code'].' budget', 'href' => route('events.show', [$e['code'], 'tab' => 'budget'])]], $tools->calls());
    }

    private function wrapReport(string $code, Tools $tools): Answer
    {
        $r = $tools->getEventWrapFacts($code);
        $f = $r['facts'];

        if (! $r['allowed'] || $f === null) {
            return new Answer(["I can’t find {$code} among the events you can see."], tools: $tools->calls(), refused: true);
        }

        $e = $f['event'];
        $paragraphs = ["Draft wrap report: {$e['code']} {$e['name']} for {$e['client']}, {$e['dates']} in {$e['city']}, produced by {$e['producer']}. Now at {$e['stage']}."];
        $bullets = [];

        if (! empty($e['phases'])) {
            $bullets[] = 'Phases: '.$e['phases'].'.';
        }

        $bullets[] = "Crew: {$f['crew_confirmed']} confirmed".($f['crew_by_role'] ? ' ('.implode(', ', $f['crew_by_role']).')' : '').'.';
        $bullets[] = "Tasks: {$f['tasks_done']} done".($f['tasks_open'] ? ', still open: '.implode('; ', $f['tasks_open']) : ', none open').'.';

        if (! empty($f['disputed_invoices'])) {
            $bullets[] = 'Disputed invoices: '.implode(', ', $f['disputed_invoices']).'.';
        }

        if (isset($f['financials'])) {
            $m = $f['financials'];
            $bullets[] = "Money: revenue {$m['revenue']}, forecast cost {$m['forecast']} against a {$m['budget']} budget, margin {$m['margin']} ({$m['margin_percent']}%).";
        }

        $bullets[] = 'Still to add: what went well, what to change next time, and client feedback.';

        return new Answer($paragraphs, $bullets, [['label' => $e['code'], 'href' => route('events.show', $e['code'])]], $tools->calls());
    }

    private function crewSuggestions(string $code, Tools $tools): Answer
    {
        $r = $tools->listCrewCalls($code);

        if (! $r['allowed']) {
            return new Answer(['Suggesting crew is for producers and heads of department.'], tools: $tools->calls(), refused: true);
        }

        $open = array_filter($r['calls'], fn ($c) => $c['open'] > 0);

        if ($open === []) {
            return new Answer(["Every crew call on {$r['event']} is filled."], tools: $tools->calls());
        }

        $bullets = [];
        $sources = [];

        foreach ($open as $call) {
            $found = $tools->findAvailablePeople($call['role'], $call['start_date'], $call['end_date']);
            $people = array_slice($found['people'], 0, 3);
            $bullets[] = "{$call['role']}, {$call['dates']} ({$call['open']} open): ".($people
                ? implode('; ', array_map(fn ($p) => $p['name'].', '.$p['based_in'].(isset($p['rating']) ? ", rated {$p['rating']}" : ''), $people))
                : 'nobody with that role is free').'.';

            foreach ($people as $p) {
                $sources['p'.$p['id']] = ['label' => $p['name'], 'href' => route('people.show', $p['id'])];
            }
        }

        $sources[] = ['label' => $code.' crew', 'href' => route('events.show', [$code, 'tab' => 'crew'])];

        return new Answer(
            ["Free for the open places on {$r['event']}, best match first. Nobody is booked until you send the request."],
            $bullets,
            array_values($sources),
            $tools->calls(),
        );
    }

    private function myLeave(Tools $tools): Answer
    {
        $r = $tools->getMyLeaveBalance();
        $b = $r['balance'];

        if (! $r['allowed'] || $b === null) {
            return new Answer(['Leave balances are for employees; Hive doesn’t hold one for you.'], tools: $tools->calls(), refused: true);
        }

        $paragraphs = [sprintf('You have %s of annual leave left in %d, of %s.', Display::days($b['remaining_days']), $b['leave_year'], Display::days($b['entitlement_days']))];
        $bullets = [Display::days($b['taken_days']).' taken, '.Display::days($b['booked_days']).' booked.'];

        if ($b['waiting_for_approval_days'] > 0) {
            $bullets[] = Display::days($b['waiting_for_approval_days']).' waiting for approval.';
        }

        if ($b['toil_remaining_days'] > 0) {
            $bullets[] = Display::days($b['toil_remaining_days']).' of time off in lieu to take.';
        }

        return new Answer($paragraphs, $bullets, [['label' => 'Leave', 'href' => route('leave')]], $tools->calls());
    }
}
