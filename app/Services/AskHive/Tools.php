<?php

namespace App\Services\AskHive;

use App\Demo\Insights;
use App\Demo\Rows;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Enums\DocumentStatus;
use App\Enums\EventStage;
use App\Services\Leave\LeaveBook;
use App\Support\DateRange;
use App\Support\Display;
use App\Support\Money;

/**
 * The read-only tools Ask Hive may call. Each one runs through the same
 * viewer scopes and permissions as the screens, and returns only fields the
 * user may see: no rates without rates.view, never sickness, passport or
 * emergency contact details.
 *
 * Every call is recorded so it can be logged with the request.
 */
class Tools
{
    /** @var list<array{tool: string, input: array<string, mixed>, allowed: bool}> */
    private array $calls = [];

    public function __construct(
        private readonly Workspace $workspace,
        private readonly Viewer $viewer,
        private readonly Insights $insights,
    ) {}

    /**
     * Tool definitions in the shape the Anthropic Messages API expects.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public static function definitions(): array
    {
        $none = ['type' => 'object', 'properties' => new \stdClass];
        $date = ['type' => 'string', 'format' => 'date', 'description' => 'YYYY-MM-DD'];
        $code = ['type' => 'string', 'description' => 'Event code, for example EV-26114'];
        $range = ['type' => 'object', 'properties' => ['start_date' => $date, 'end_date' => $date]];

        return [
            ['name' => 'search_events', 'description' => 'Find events the user can see, by text (name, city, code, country) or stage (enquiry, proposal-sent, confirmed, pre-production, live, wrap, reconciled, closed, lost, cancelled).', 'input_schema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string'], 'stage' => ['type' => 'string']]]],
            ['name' => 'get_event_summary', 'description' => 'Summary of one event by code: client, stage, dates, city, producer and phases.', 'input_schema' => ['type' => 'object', 'properties' => ['event_code' => $code], 'required' => ['event_code']]],
            ['name' => 'find_available_people', 'description' => 'People free for a role between two dates, ranked by skill level and rating. Producers and heads of department only.', 'input_schema' => ['type' => 'object', 'properties' => ['role' => ['type' => 'string', 'description' => 'Role name, for example AV Technician'], 'skill' => ['type' => 'string', 'description' => 'Optional skill name, for example disguise'], 'start_date' => $date, 'end_date' => $date], 'required' => ['role', 'start_date', 'end_date']]],
            ['name' => 'list_clashes', 'description' => 'Double bookings the user can see, optionally in a date range.', 'input_schema' => $range],
            ['name' => 'get_budget_health', 'description' => 'Budget, forecast, margin, over-budget lines and disputed invoices for active events, or for one event. Needs budget access.', 'input_schema' => ['type' => 'object', 'properties' => ['event_code' => $code]]],
            ['name' => 'list_pending_approvals', 'description' => 'Counts of everything waiting for the user to approve: timesheets, supplier invoices, leave.', 'input_schema' => $none],
            ['name' => 'list_pending_timesheet_entries', 'description' => 'Timesheet entries waiting for the user to approve, with hours and working-time flags.', 'input_schema' => $none],
            ['name' => 'get_leave_calendar', 'description' => 'Who is away between two dates. Sensitive leave shows only as "Absent".', 'input_schema' => ['type' => 'object', 'properties' => ['start_date' => $date, 'end_date' => $date], 'required' => ['start_date', 'end_date']]],
            ['name' => 'get_my_leave_balance', 'description' => 'The user’s own annual leave and time off in lieu for this leave year.', 'input_schema' => $none],
            ['name' => 'list_my_bookings', 'description' => 'The user’s own upcoming bookings.', 'input_schema' => $none],
            ['name' => 'list_expiring_documents', 'description' => 'Documents that have expired or expire within 30 days. HR only.', 'input_schema' => $none],
            ['name' => 'list_crew_calls', 'description' => 'An event’s crew calls with how many places are filled and open.', 'input_schema' => ['type' => 'object', 'properties' => ['event_code' => $code], 'required' => ['event_code']]],
            ['name' => 'get_event_wrap_facts', 'description' => 'Facts for an event’s wrap report: dates, phases, crew by role, tasks, disputes and, with budget access, the financial outcome.', 'input_schema' => ['type' => 'object', 'properties' => ['event_code' => $code], 'required' => ['event_code']]],
        ];
    }

    /**
     * Runs a tool the model asked for. Bad input comes back as an error the
     * model can read, never an exception.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(string $name, array $input): array
    {
        $str = fn (string $key): ?string => isset($input[$key]) && is_scalar($input[$key]) && trim((string) $input[$key]) !== '' ? trim((string) $input[$key]) : null;
        $date = function (string $key) use ($str): ?string {
            $value = $str($key);

            return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null;
        };

        $missing = fn (string ...$keys): array => ['error' => 'Missing or invalid input: '.implode(', ', $keys).'. Dates are YYYY-MM-DD.'];

        return match ($name) {
            'search_events' => $this->searchEvents($str('text') ?? '', $str('stage') !== null ? mb_strtolower((string) $str('stage')) : null),
            'get_event_summary' => $str('event_code') ? $this->getEventSummary((string) $str('event_code')) : $missing('event_code'),
            'find_available_people' => $str('role') && $date('start_date') && $date('end_date') && $date('start_date') <= $date('end_date')
                ? $this->findAvailablePeople((string) $str('role'), (string) $date('start_date'), (string) $date('end_date'), $this->skillId($str('skill')))
                : $missing('role', 'start_date', 'end_date'),
            'list_clashes' => $this->listClashes($date('start_date'), $date('end_date')),
            'get_budget_health' => $this->getBudgetHealth($str('event_code')),
            'list_pending_approvals' => $this->listPendingApprovals(),
            'list_pending_timesheet_entries' => $this->listPendingTimesheetEntries(),
            'get_leave_calendar' => $date('start_date') && $date('end_date') && $date('start_date') <= $date('end_date')
                ? $this->getLeaveCalendar((string) $date('start_date'), (string) $date('end_date'))
                : $missing('start_date', 'end_date'),
            'list_my_bookings' => $this->listMyBookings(),
            'get_my_leave_balance' => $this->getMyLeaveBalance(),
            'list_expiring_documents' => $this->listExpiringDocuments(),
            'list_crew_calls' => $str('event_code') ? $this->listCrewCalls((string) $str('event_code')) : $missing('event_code'),
            'get_event_wrap_facts' => $str('event_code') ? $this->getEventWrapFacts((string) $str('event_code')) : $missing('event_code'),
            default => ['error' => "There is no tool called {$name}."],
        };
    }

    private function skillId(?string $name): ?int
    {
        if ($name === null) {
            return null;
        }

        $skill = $this->workspace->skills()->first(fn ($s) => mb_strtolower($s['name']) === mb_strtolower($name));

        return $skill['id'] ?? null;
    }

    /**
     * @return list<array{tool: string, input: array<string, mixed>, allowed: bool}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function record(string $tool, array $input, bool $allowed): void
    {
        $this->calls[] = ['tool' => $tool, 'input' => $input, 'allowed' => $allowed];
    }

    /**
     * @return array{allowed: bool, events: list<array<string, mixed>>}
     */
    public function searchEvents(string $text = '', ?string $stage = null): array
    {
        $allowed = $this->viewer->can('events.view');
        $this->record('search_events', compact('text', 'stage'), $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'events' => []];
        }

        $events = $this->viewer->events()
            ->when($text !== '', fn ($c) => $c->filter(fn ($e) => str_contains(mb_strtolower($e['name'].' '.$e['city'].' '.$e['code'].' '.$e['country']), mb_strtolower($text))))
            ->when($stage, fn ($c) => $c->where('stage', $stage))
            ->map(fn ($e) => $this->eventFields($e))
            ->values()->all();

        return ['allowed' => true, 'events' => $events];
    }

    /**
     * @return array{allowed: bool, event: array<string, mixed>|null}
     */
    public function getEventSummary(string $code): array
    {
        $event = $this->workspace->eventByCode($code);
        $allowed = $event !== null && $this->viewer->canSeeEvent($event);
        $this->record('get_event_summary', ['event_code' => $code], $allowed);

        return ['allowed' => $allowed, 'event' => $allowed ? $this->eventFields($event) : null];
    }

    /**
     * @return array{allowed: bool, people: list<array<string, mixed>>, role: string, dates: string}
     */
    public function findAvailablePeople(string $role, string $start, string $end, ?int $skillId = null, int $minLevel = 0): array
    {
        $allowed = $this->viewer->can('crew.manage') || $this->viewer->can('rota.view_all');
        $this->record('find_available_people', compact('role', 'start', 'end', 'skillId'), $allowed);
        $range = DateRange::of($start, $end);

        if (! $allowed) {
            return ['allowed' => false, 'people' => [], 'role' => $role, 'dates' => $range->format()];
        }

        $showRates = $this->viewer->can('rates.view');
        $people = $this->workspace->availablePeople($role, $range, $skillId, $minLevel)
            ->take(5)
            ->map(fn ($row) => array_filter([
                'id' => $row['person']['id'],
                'name' => $row['person']['first_name'].' '.$row['person']['last_name'],
                'based_in' => $row['person']['location_city'],
                'skill_level' => $row['skill_level'] ?: null,
                'rating' => $row['rating'],
                'day_rate' => $showRates ? $row['rate']?->format(false) : null,
            ], fn ($v) => $v !== null))
            ->values()->all();

        return ['allowed' => true, 'people' => $people, 'role' => $role, 'dates' => $range->format()];
    }

    /**
     * @return array{allowed: bool, clashes: list<array<string, mixed>>}
     */
    public function listClashes(?string $start = null, ?string $end = null): array
    {
        $this->record('list_clashes', compact('start', 'end'), true);
        $range = $start && $end ? DateRange::of($start, $end) : null;

        $clashes = collect($this->insights->clashes())
            ->filter(fn ($c) => $range === null || $c->dates->overlaps($range))
            ->map(fn ($c) => [
                'person' => $this->workspace->personName($c->personId),
                'person_id' => $c->personId,
                'dates' => $c->dates->format(),
                'bookings' => collect([$c->first, $c->second])->map(fn ($b) => $b->kind === 'assignment' ? $b->label : ($b->kind === 'leave' ? 'Absent' : 'Unavailable'))->all(),
                'event_codes' => collect($c->eventIds())->map(fn ($id) => $this->workspace->event($id)['code'])->all(),
            ])
            ->values()->all();

        return ['allowed' => true, 'clashes' => $clashes];
    }

    /**
     * @return array{allowed: bool, events: list<array<string, mixed>>}
     */
    public function getBudgetHealth(?string $code = null): array
    {
        $allowed = $this->viewer->can('budgets.view');
        $this->record('get_budget_health', ['event_code' => $code], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'events' => []];
        }

        $events = $code
            ? collect([$this->workspace->eventByCode($code)])->filter()->filter(fn ($e) => $this->viewer->canSeeBudget($e))
            : $this->insights->activeEventsWithBudgets();

        $rows = $events->map(function ($e) {
            $f = $this->workspace->financials($e['id']);

            if (! $f) {
                return null;
            }

            $lines = $this->workspace->budgetLines($e['id'])
                ->filter(fn ($l) => $l['forecast']->greaterThan($l['budget']))
                ->map(fn ($l) => $this->workspace->costCategoryName($l['category']).' ('.$l['forecast']->minus($l['budget'])->format(false).')')
                ->values()->all();

            return [
                'code' => $e['code'], 'name' => $e['name'], 'stage' => EventStage::from($e['stage'])->label(),
                'budget' => $f->budget->format(false), 'forecast' => $f->forecast()->format(false),
                'forecast_percent_of_budget' => $f->forecastPercentOfBudget(), 'margin_percent' => $f->marginPercent(),
                'health' => $f->health?->label(), 'over_budget_lines' => $lines,
                'open_disputes' => $this->workspace->supplierInvoices()->where('event_id', $e['id'])->where('status', 'disputed')->pluck('invoice_number')->all(),
                'start_date' => $e['start_date'],
            ];
        })->filter()->values()->all();

        return ['allowed' => true, 'events' => $rows];
    }

    /**
     * @return array{allowed: bool, timesheet_entries: int, flagged: int, groups: list<string>, invoices: list<string>, leave: list<string>}
     */
    public function listPendingApprovals(): array
    {
        $this->record('list_pending_approvals', [], true);
        $groups = $this->insights->pendingEntries();

        return [
            'allowed' => true,
            'timesheet_entries' => $groups->sum(fn ($g) => count($g['items'])),
            'flagged' => $groups->sum(fn ($g) => collect($g['items'])->filter(fn ($i) => $i['flags'] !== [])->count()),
            'groups' => $groups->map(fn ($g) => $g['label'].' ('.count($g['items']).')')->values()->all(),
            'invoices' => $this->insights->invoicesAwaitingApproval()->map(fn ($i) => $i['invoice_number'])->values()->all(),
            'leave' => $this->insights->leaveAwaitingApproval()->map(fn ($l) => $this->workspace->personName($l['person_id']))->values()->all(),
        ];
    }

    /**
     * Never returns the leave type to people who may not know it.
     *
     * @return array{allowed: bool, away: list<array<string, mixed>>}
     */
    public function getLeaveCalendar(string $start, string $end): array
    {
        $allowed = $this->viewer->can('leave.view_calendar');
        $this->record('get_leave_calendar', compact('start', 'end'), $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'away' => []];
        }

        $range = DateRange::of($start, $end);
        $away = $this->workspace->leaveRequests()
            ->filter(fn ($l) => $l['status'] === 'approved' && DateRange::of($l['start_date'], $l['end_date'])->overlaps($range))
            ->map(fn ($l) => ['person' => $this->workspace->personName($l['person_id']), 'person_id' => $l['person_id'], 'type' => $this->viewer->leaveLabel($l), 'dates' => DateRange::of($l['start_date'], $l['end_date'])->format()])
            ->values()->all();

        return ['allowed' => true, 'away' => $away];
    }

    /**
     * @return array{allowed: bool, bookings: list<array<string, mixed>>}
     */
    public function listMyBookings(): array
    {
        $this->record('list_my_bookings', [], true);
        $today = $this->workspace->today()->toDateString();

        $bookings = $this->workspace->assignments()
            ->where('person_id', $this->viewer->personId())
            ->filter(fn ($a) => $a['end_date'] >= $today && in_array($a['status'], ['confirmed', 'first-hold', 'second-hold'], true))
            ->sortBy('start_date')
            ->map(fn ($a) => ['code' => $this->workspace->event($a['event_id'])['code'], 'event' => $this->workspace->event($a['event_id'])['name'], 'city' => $this->workspace->event($a['event_id'])['city'], 'dates' => DateRange::of($a['start_date'], $a['end_date'])->format(), 'status' => $a['status'], 'answered' => $a['responded_at'] !== null])
            ->values()->all();

        return ['allowed' => true, 'bookings' => $bookings];
    }

    /**
     * @return array{allowed: bool, balance: array<string, mixed>|null}
     */
    public function getMyLeaveBalance(): array
    {
        $personId = $this->viewer->personId();
        $allowed = $personId !== null && $this->viewer->can('leave.request');
        $this->record('get_my_leave_balance', [], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'balance' => null];
        }

        $b = app(LeaveBook::class)->balance($personId);

        return ['allowed' => true, 'balance' => [
            'leave_year' => $b->year, 'entitlement_days' => $b->entitlement(), 'carried_over_days' => $b->carriedOver,
            'taken_days' => $b->taken, 'booked_days' => $b->booked, 'waiting_for_approval_days' => $b->pending,
            'remaining_days' => $b->remaining(), 'toil_remaining_days' => $b->toilRemaining(),
        ]];
    }

    /**
     * @return array{allowed: bool, documents: list<array<string, mixed>>}
     */
    public function listExpiringDocuments(): array
    {
        $allowed = $this->viewer->can('documents.view');
        $this->record('list_expiring_documents', [], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'documents' => []];
        }

        $docs = $this->workspace->documents()
            ->filter(fn ($d) => $this->workspace->documentStatus($d) !== DocumentStatus::Valid)
            ->sortBy('expires_at')
            ->map(fn ($d) => ['person' => $this->workspace->personName($d['person_id']), 'person_id' => $d['person_id'], 'title' => $d['title'], 'expires' => Display::date($d['expires_at']), 'status' => $this->workspace->documentStatus($d)->label()])
            ->values()->all();

        return ['allowed' => true, 'documents' => $docs];
    }

    /**
     * The entries waiting for this user, with flags and no costs.
     *
     * @return array{allowed: bool, entries: list<array<string, mixed>>}
     */
    public function listPendingTimesheetEntries(): array
    {
        $allowed = $this->viewer->can('timesheets.approve');
        $this->record('list_pending_timesheet_entries', [], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'entries' => []];
        }

        $entries = [];

        foreach ($this->insights->pendingEntries() as $key => $group) {
            foreach ($group['items'] as $item) {
                $entry = $item['entry'];
                $entries[] = array_filter([
                    'event' => $group['event'] ? $group['event']['code'].' '.$group['event']['name'] : $group['label'],
                    'person' => $this->workspace->personName($item['sheet']['person_id']),
                    'person_id' => $item['sheet']['person_id'],
                    'date' => Display::date($entry['date']),
                    'times' => $entry['start_time'] && $entry['end_time'] ? $entry['start_time'].' to '.$entry['end_time'] : null,
                    'hours' => $entry['hours'] ?: null,
                    'days' => $entry['days'] ?: null,
                    'phase' => $entry['phase'],
                    'notes' => $entry['notes'],
                    'flags' => array_map(fn ($f) => $f['message'], $item['flags']),
                    'waiting_days' => (int) $item['waiting_since']->diffInDays($this->workspace->today()),
                ], fn ($v) => $v !== null && $v !== '');
            }
        }

        return ['allowed' => true, 'entries' => $entries];
    }

    /**
     * @return array{allowed: bool, event: string|null, calls: list<array<string, mixed>>}
     */
    public function listCrewCalls(string $code): array
    {
        $event = $this->workspace->eventByCode($code);
        $allowed = $event !== null && $this->viewer->canSeeEvent($event) && ($this->viewer->can('crew.manage') || $this->viewer->can('rota.view_all'));
        $this->record('list_crew_calls', ['event_code' => $code], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'event' => null, 'calls' => []];
        }

        $assignments = $this->workspace->assignments()->where('event_id', $event['id']);
        $calls = $this->workspace->crewCalls()->where('event_id', $event['id'])->sortBy('start_date')
            ->map(function ($call) use ($assignments) {
                $filled = $assignments->where('crew_call_id', $call['id'])->filter(fn ($a) => in_array($a['status'], ['confirmed', 'first-hold', 'second-hold'], true))->count();

                return array_filter([
                    'role' => $call['role_name'],
                    'skill' => $call['skill_id'] ? ($this->workspace->skills()->get($call['skill_id'])['name'] ?? null) : null,
                    'min_skill_level' => $call['min_skill_level'] ?: null,
                    'phase' => $call['phase'],
                    'start_date' => $call['start_date'], 'end_date' => $call['end_date'],
                    'dates' => DateRange::of($call['start_date'], $call['end_date'])->format(),
                    'places' => $call['quantity'], 'filled' => $filled, 'open' => max(0, $call['quantity'] - $filled),
                ], fn ($v) => $v !== null && $v !== '');
            })->values()->all();

        return ['allowed' => true, 'event' => $event['code'].' '.$event['name'], 'calls' => $calls];
    }

    /**
     * What a wrap report needs. Money only with budget access; no rates or
     * individual pay either way.
     *
     * @return array{allowed: bool, facts: array<string, mixed>|null}
     */
    public function getEventWrapFacts(string $code): array
    {
        $event = $this->workspace->eventByCode($code);
        $allowed = $event !== null && $this->viewer->canSeeEvent($event);
        $this->record('get_event_wrap_facts', ['event_code' => $code], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'facts' => null];
        }

        $crew = $this->workspace->assignments()->where('event_id', $event['id'])->where('status', 'confirmed');
        $roles = $crew->map(fn ($a) => $this->workspace->crewCalls()->get($a['crew_call_id'])['role_name'] ?? 'Crew')->countBy()->sortDesc()->map(fn ($n, $role) => "{$role} × {$n}")->values()->all();
        $tasks = Rows::of($event['tasks']);
        $open = $tasks->where('status', '!=', 'done');

        $facts = array_filter([
            'event' => $this->eventFields($event),
            'expected_attendance' => $event['expected_attendance'],
            'crew_confirmed' => $crew->pluck('person_id')->unique()->count(),
            'crew_by_role' => $roles,
            'tasks_done' => $tasks->count() - $open->count(),
            'tasks_open' => $open->map(fn ($t) => $t['title'].($t['due_date'] ? ' (due '.Display::date($t['due_date']).')' : ''))->values()->all(),
            'stage_history' => Rows::of($event['stage_history'])->map(fn ($c) => EventStage::from($c['to'])->label().' on '.Display::date(substr($c['at'], 0, 10)))->values()->all(),
            'disputed_invoices' => $this->viewer->canSeeBudget($event) ? $this->workspace->supplierInvoices()->where('event_id', $event['id'])->where('status', 'disputed')->pluck('invoice_number')->values()->all() : null,
        ], fn ($v) => $v !== null);

        if ($this->viewer->canSeeBudget($event) && ($f = $this->workspace->financials($event['id']))) {
            $facts['financials'] = [
                'revenue' => $f->revenue->format(false), 'budget' => $f->budget->format(false),
                'actual' => $f->actual->format(false), 'committed' => $f->committed->format(false),
                'forecast' => $f->forecast()->format(false), 'margin' => $f->margin()->format(false),
                'margin_percent' => $f->marginPercent(), 'health' => $f->health?->label(),
            ];
        }

        return ['allowed' => true, 'facts' => $facts];
    }

    /**
     * The event fields Ask Hive may see. Fees only with budget access.
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private function eventFields(array $e): array
    {
        return array_filter([
            'code' => $e['code'], 'name' => $e['name'], 'stage' => EventStage::from($e['stage'])->label(),
            'client' => $this->workspace->clients()->get($e['client_id'])['name'] ?? null,
            'city' => $e['city'], 'country' => $e['country'],
            'dates' => Display::eventDates($e),
            'start_date' => $e['start_date'], 'end_date' => $e['end_date'],
            'producer' => $this->workspace->personName($e['producer_id']),
            'phases' => Rows::of($e['phases'])->map(fn ($p) => ucfirst($p['type']).' '.DateRange::of($p['start_date'], $p['end_date'])->format())->implode(', '),
            'client_fee' => $this->viewer->canSeeBudget($e) && $e['client_fee_minor'] ? Money::of($e['client_fee_minor'])->format(false) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
