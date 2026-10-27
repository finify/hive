<?php

namespace App\Services\AskHive;

use App\Demo\Insights;
use App\Demo\Rows;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Enums\DocumentStatus;
use App\Enums\EventStage;
use App\Models\Checklist;
use App\Reports\Reports;
use App\Services\Leave\LeaveBook;
use App\Support\DateRange;
use App\Support\Display;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The tools Ask Hive may call. The read tools run through the same viewer
 * scopes and permissions as the screens, and return only fields the user
 * may see: no rates without rates.view, never sickness, passport or
 * emergency contact details.
 *
 * The propose_ tools change nothing: each checks a change with Proposals
 * and leaves it for the user to confirm in the panel.
 *
 * Every call is recorded so it can be logged with the request.
 */
class Tools
{
    /** @var list<array{tool: string, input: array<string, mixed>, allowed: bool}> */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $proposed = [];

    /** At most this many changes proposed in one answer. */
    private const MAX_PROPOSALS = 5;

    private readonly Proposals $changes;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly Viewer $viewer,
        private readonly Insights $insights,
    ) {
        $this->changes = new Proposals($workspace);
    }

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
            ['name' => 'list_my_leave', 'description' => 'The user’s own leave requests this leave year and next, with ids, dates, days and status.', 'input_schema' => $none],
            ['name' => 'list_leave_to_decide', 'description' => 'Leave requests waiting for the user to approve, with ids, and any bookings each one overlaps.', 'input_schema' => $none],
            ['name' => 'get_my_timesheet', 'description' => 'The user’s own timesheet for the week containing a date (default this week): entries with ids, hours, status and any return comment.', 'input_schema' => ['type' => 'object', 'properties' => ['week' => $date]]],
            ['name' => 'get_event_tasks', 'description' => 'An event’s tasks with ids, owners, due dates and status.', 'input_schema' => ['type' => 'object', 'properties' => ['event_code' => $code], 'required' => ['event_code']]],
            ['name' => 'get_event_crew', 'description' => 'Who is booked on an event: assignment ids, people, roles, dates and booking status. Producers and heads of department only.', 'input_schema' => ['type' => 'object', 'properties' => ['event_code' => $code], 'required' => ['event_code']]],
            ['name' => 'search_people', 'description' => 'Find people in the directory by name, job title, department, skill or city. Returns ids and work details only.', 'input_schema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]],
            ['name' => 'get_person', 'description' => 'One person’s work profile by id: job title, department, manager, skills, upcoming bookings and approved leave dates.', 'input_schema' => ['type' => 'object', 'properties' => ['person_id' => ['type' => 'integer']], 'required' => ['person_id']]],
            ['name' => 'search_directory', 'description' => 'Find clients, suppliers and venues by name, city or category.', 'input_schema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]],
            ['name' => 'list_purchase_orders', 'description' => 'Purchase orders, optionally for one event or by status (draft, awaiting-approval, approved, sent, partially-invoiced, invoiced, closed, cancelled). Needs PO access.', 'input_schema' => ['type' => 'object', 'properties' => ['event_code' => $code, 'status' => ['type' => 'string']]]],
            ['name' => 'list_supplier_invoices', 'description' => 'Supplier invoices, optionally for one event or by status (received, matched, disputed, approved, paid). Needs invoice access.', 'input_schema' => ['type' => 'object', 'properties' => ['event_code' => $code, 'status' => ['type' => 'string']]]],
            ['name' => 'list_my_expenses', 'description' => 'The user’s own expense and mileage claims with status.', 'input_schema' => $none],
            ['name' => 'list_my_notifications', 'description' => 'The user’s recent Hive notifications, newest first.', 'input_schema' => $none],
            ['name' => 'list_my_checklist_items', 'description' => 'Open onboarding and offboarding items the user can tick, with ids.', 'input_schema' => $none],
            ['name' => 'list_reports', 'description' => 'The reports the user can run, with keys and filters.', 'input_schema' => $none],
            ['name' => 'run_report', 'description' => 'Run one report by key (from list_reports) with optional filters; returns up to 25 rows.', 'input_schema' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'filters' => ['type' => 'object']], 'required' => ['key']]],
            ['name' => 'get_event_wrap_facts', 'description' => 'Facts for an event’s wrap report: dates, phases, crew by role, tasks, disputes and, with budget access, the financial outcome.', 'input_schema' => ['type' => 'object', 'properties' => ['event_code' => $code], 'required' => ['event_code']]],
        ];
    }

    /**
     * The read tools plus a propose_ tool for each change this user's role
     * can make. Write tools are never offered to a role that can't use them.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitionsFor(): array
    {
        $propose = array_map(fn (Operations\Operation $op) => [
            'name' => 'propose_'.$op::name(),
            'description' => 'Propose: '.$op->description().' Nothing changes until the user confirms.',
            'input_schema' => $op->schema(),
        ], $this->changes->offeredTo($this->viewer->user));

        return [...self::definitions(), ...$propose];
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

        if (str_starts_with($name, 'propose_')) {
            return $this->propose(substr($name, 8), $input);
        }

        return match ($name) {
            'list_my_leave' => $this->listMyLeave(),
            'list_leave_to_decide' => $this->listLeaveToDecide(),
            'get_my_timesheet' => $this->getMyTimesheet($date('week')),
            'get_event_tasks' => $str('event_code') ? $this->getEventTasks((string) $str('event_code')) : $missing('event_code'),
            'get_event_crew' => $str('event_code') ? $this->getEventCrew((string) $str('event_code')) : $missing('event_code'),
            'search_people' => $str('text') ? $this->searchPeople((string) $str('text')) : $missing('text'),
            'get_person' => is_numeric($input['person_id'] ?? null) ? $this->getPerson((int) $input['person_id']) : $missing('person_id'),
            'search_directory' => $str('text') ? $this->searchDirectory((string) $str('text')) : $missing('text'),
            'list_purchase_orders' => $this->listPurchaseOrders($str('event_code'), $str('status')),
            'list_supplier_invoices' => $this->listSupplierInvoices($str('event_code'), $str('status')),
            'list_my_expenses' => $this->listMyExpenses(),
            'list_my_notifications' => $this->listMyNotifications(),
            'list_my_checklist_items' => $this->listMyChecklistItems(),
            'list_reports' => $this->listReports(),
            'run_report' => $str('key') ? $this->runReport((string) $str('key'), is_array($input['filters'] ?? null) ? $input['filters'] : []) : $missing('key'),
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
     * The changes proposed while answering, for the panel to confirm.
     *
     * @return list<array<string, mixed>>
     */
    public function proposals(): array
    {
        return $this->proposed;
    }

    /**
     * Checks a change and, if Hive would accept it, leaves it for the user
     * to confirm. A change Hive would refuse comes back as an error the
     * model can explain or correct.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function propose(string $name, array $input): array
    {
        $operation = $this->changes->operation($name);

        if ($operation === null || ! $operation->offeredTo($this->viewer->user)) {
            $this->record('propose_'.$name, $input, false);

            return ['allowed' => false, 'error' => 'The user’s role can’t make that change in Hive.'];
        }

        $this->record('propose_'.$name, $input, true);

        foreach ($this->proposed as $p) {
            if ($p['operation'] === $name && $p['input'] == $input) {
                return ['proposed' => true, 'summary' => $p['summary'], 'note' => 'Already proposed.'];
            }
        }

        if (count($this->proposed) >= self::MAX_PROPOSALS) {
            return ['error' => 'That’s as many changes as one answer can propose. Ask the user to confirm these first.'];
        }

        $check = $this->changes->check($this->viewer->user, $operation, $input);

        if (! $check['ok']) {
            return ['proposed' => false, 'error' => 'Hive would refuse this change: '.$check['error']];
        }

        $this->proposed[] = Proposals::pending($name, $input, $check['summary']);

        return [
            'proposed' => true,
            'summary' => $check['summary'],
            'note' => 'Shown to the user with Confirm and Cancel buttons. Nothing has changed yet: say what you have proposed and ask them to check it and confirm.',
        ];
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
            ->map(fn ($a) => [
                'assignment_id' => $a['id'], 'code' => $this->workspace->event($a['event_id'])['code'], 'event' => $this->workspace->event($a['event_id'])['name'],
                'city' => $this->workspace->event($a['event_id'])['city'], 'role' => $this->workspace->crewCalls()->get($a['crew_call_id'])['role_name'] ?? null,
                'dates' => DateRange::of($a['start_date'], $a['end_date'])->format(), 'status' => $a['status'],
                'waiting_for_your_answer' => $this->viewer->user->can('assignment.respond', [$a]),
            ])
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
                    'entry_id' => $entry['id'],
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
                    'crew_call_id' => $call['id'],
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
     * @return array{allowed: bool, leave: list<array<string, mixed>>}
     */
    public function listMyLeave(): array
    {
        $personId = $this->viewer->personId();
        $allowed = $personId !== null && $this->viewer->can('leave.request');
        $this->record('list_my_leave', [], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'leave' => []];
        }

        $today = $this->workspace->today()->toDateString();
        $leave = $this->workspace->leaveRequests()->where('person_id', $personId)
            ->filter(fn ($l) => $l['end_date'] >= $this->workspace->today()->subYear()->toDateString())
            ->sortBy('start_date')
            ->map(fn ($l) => [
                'leave_id' => $l['id'], 'type' => $this->workspace->leaveType($l['leave_type'])['name'] ?? 'Leave',
                'dates' => DateRange::of($l['start_date'], $l['end_date'])->format(), 'days' => $l['days'], 'status' => $l['status'],
                'can_cancel' => in_array($l['status'], ['pending', 'approved'], true) && $l['start_date'] > $today,
            ])->values()->all();

        return ['allowed' => true, 'leave' => $leave];
    }

    /**
     * @return array{allowed: bool, leave: list<array<string, mixed>>}
     */
    public function listLeaveToDecide(): array
    {
        $allowed = $this->viewer->can('leave.approve');
        $this->record('list_leave_to_decide', [], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'leave' => []];
        }

        $leave = $this->insights->leaveAwaitingApproval()->map(function ($l) {
            $range = DateRange::of($l['start_date'], $l['end_date']);
            $overlaps = $this->workspace->assignments()->where('person_id', $l['person_id'])
                ->filter(fn ($a) => in_array($a['status'], ['confirmed', 'first-hold', 'second-hold'], true) && DateRange::of($a['start_date'], $a['end_date'])->overlaps($range))
                ->map(fn ($a) => $this->workspace->event($a['event_id'])['code'])->unique()->values()->all();

            return [
                'leave_id' => $l['id'], 'person' => $this->workspace->personName($l['person_id']), 'person_id' => $l['person_id'],
                'type' => $this->viewer->leaveLabel($l), 'dates' => $range->format(), 'days' => $l['days'], 'overlaps_bookings_on' => $overlaps,
            ];
        })->values()->all();

        return ['allowed' => true, 'leave' => $leave];
    }

    /**
     * @return array{allowed: bool, week: string|null, status?: string, total_hours?: float|int, entries: list<array<string, mixed>>}
     */
    public function getMyTimesheet(?string $week = null): array
    {
        $personId = $this->viewer->personId();
        $allowed = $personId !== null && $this->viewer->can('timesheets.submit');
        $this->record('get_my_timesheet', ['week' => $week], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'week' => null, 'entries' => []];
        }

        $start = ($week ? CarbonImmutable::parse($week) : $this->workspace->today())->startOfWeek()->toDateString();
        $sheet = $this->workspace->timesheets()->first(fn ($s) => $s['person_id'] === $personId && $s['week_start'] === $start);

        if ($sheet === null) {
            return ['allowed' => true, 'week' => Display::date($start), 'status' => 'nothing recorded', 'entries' => []];
        }

        $entries = Rows::of($sheet['entries'])->sortBy(fn ($e) => $e['date'].$e['start_time'])->map(fn ($e) => array_filter([
            'entry_id' => $e['id'], 'date' => Display::date($e['date'], true),
            'work' => $e['event_id'] ? ($this->workspace->event($e['event_id'])['code'] ?? 'Event') : $e['internal_code'],
            'times' => $e['start_time'] && $e['end_time'] ? $e['start_time'].' to '.$e['end_time'] : null,
            'break_minutes' => $e['break_minutes'], 'hours' => $e['hours'], 'status' => $e['status'],
            'return_comment' => $e['return_comment'], 'notes' => $e['notes'],
        ], fn ($v) => $v !== null && $v !== ''))->values()->all();

        return ['allowed' => true, 'week' => Display::date($start), 'status' => $sheet['status'], 'total_hours' => Rows::of($sheet['entries'])->sum('hours'), 'entries' => $entries];
    }

    /**
     * @return array{allowed: bool, event: string|null, tasks: list<array<string, mixed>>}
     */
    public function getEventTasks(string $code): array
    {
        $event = $this->workspace->eventByCode($code);
        $allowed = $event !== null && $this->viewer->canSeeEvent($event);
        $this->record('get_event_tasks', ['event_code' => $code], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'event' => null, 'tasks' => []];
        }

        $tasks = Rows::of($event['tasks'])->map(fn ($t) => array_filter([
            'task_id' => $t['id'], 'title' => $t['title'], 'owner' => $t['assignee_id'] ? $this->workspace->personName($t['assignee_id']) : null,
            'due' => $t['due_date'] ? Display::date($t['due_date']) : null, 'status' => $t['status'],
        ], fn ($v) => $v !== null))->values()->all();

        return ['allowed' => true, 'event' => $event['code'].' '.$event['name'], 'tasks' => $tasks];
    }

    /**
     * Who is booked, without rates.
     *
     * @return array{allowed: bool, event: string|null, crew: list<array<string, mixed>>}
     */
    public function getEventCrew(string $code): array
    {
        $event = $this->workspace->eventByCode($code);
        $allowed = $event !== null && $this->viewer->canSeeEvent($event) && ($this->viewer->can('crew.manage') || $this->viewer->can('rota.view_all'));
        $this->record('get_event_crew', ['event_code' => $code], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'event' => null, 'crew' => []];
        }

        $crew = $this->workspace->assignments()->where('event_id', $event['id'])
            ->filter(fn ($a) => in_array($a['status'], ['confirmed', 'first-hold', 'second-hold'], true))
            ->sortBy('start_date')
            ->map(fn ($a) => [
                'assignment_id' => $a['id'], 'person' => $this->workspace->personName($a['person_id']), 'person_id' => $a['person_id'],
                'role' => $this->workspace->crewCalls()->get($a['crew_call_id'])['role_name'] ?? 'Crew',
                'dates' => DateRange::of($a['start_date'], $a['end_date'])->format(), 'status' => $a['status'],
                'freelancer_answered' => $this->workspace->isFreelancer($a['person_id']) ? $a['responded_at'] !== null : null,
            ])->values()->all();

        return ['allowed' => true, 'event' => $event['code'].' '.$event['name'], 'crew' => $crew];
    }

    /**
     * @return array{allowed: bool, people: list<array<string, mixed>>}
     */
    public function searchPeople(string $text): array
    {
        $allowed = $this->viewer->canSeeDirectory();
        $this->record('search_people', ['text' => $text], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'people' => []];
        }

        $needle = mb_strtolower($text);
        $people = $this->viewer->people()
            ->filter(fn ($p) => $p['is_active'])
            ->filter(fn ($p) => str_contains(mb_strtolower(implode(' ', [
                $p['first_name'], $p['last_name'], $p['preferred_name'] ?? '', $p['job_title'] ?? '', $p['location_city'] ?? '',
                $this->workspace->departmentName($p['department_id']), ...$this->skillNames($p),
            ])), $needle))
            ->take(10)
            ->map(fn ($p) => $this->personFields($p))
            ->values()->all();

        return ['allowed' => true, 'people' => $people];
    }

    /**
     * @return array{allowed: bool, person: array<string, mixed>|null}
     */
    public function getPerson(int $id): array
    {
        $person = $this->workspace->person($id);
        $allowed = $person !== null && $this->viewer->canSeePerson($id);
        $this->record('get_person', ['person_id' => $id], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'person' => null];
        }

        $today = $this->workspace->today()->toDateString();
        $fields = $this->personFields($person) + array_filter([
            'pronouns' => $person['pronouns'] ?? null,
            'line_manager' => $person['line_manager_id'] ? $this->workspace->personName($person['line_manager_id']) : null,
            'skills' => Rows::of($person['skills'])->map(fn ($s) => ($this->workspace->skills()->get($s['skill_id'])['name'] ?? 'Skill').' (level '.$s['level'].')')->values()->all(),
            'languages' => $person['languages'] ?: null,
        ]);

        $fields['upcoming_bookings'] = $this->workspace->assignments()->where('person_id', $id)
            ->filter(fn ($a) => $a['end_date'] >= $today && in_array($a['status'], ['confirmed', 'first-hold', 'second-hold'], true))
            ->filter(fn ($a) => ($e = $this->workspace->event($a['event_id'])) && $this->viewer->canSeeEvent($e))
            ->sortBy('start_date')
            ->map(fn ($a) => $this->workspace->event($a['event_id'])['code'].', '.DateRange::of($a['start_date'], $a['end_date'])->format().' ('.$a['status'].')')
            ->values()->all();

        if ($this->viewer->can('leave.view_calendar') || $this->viewer->isSelf($id)) {
            $fields['upcoming_leave'] = $this->workspace->leaveRequests()->where('person_id', $id)
                ->filter(fn ($l) => $l['status'] === 'approved' && $l['end_date'] >= $today)
                ->map(fn ($l) => $this->viewer->leaveLabel($l).', '.DateRange::of($l['start_date'], $l['end_date'])->format())
                ->values()->all();
        }

        return ['allowed' => true, 'person' => $fields];
    }

    /**
     * Work details only: never rates, restricted fields or notes.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function personFields(array $p): array
    {
        return array_filter([
            'id' => $p['id'], 'name' => $p['first_name'].' '.$p['last_name'], 'job_title' => $p['job_title'] ?? null,
            'department' => $this->workspace->departmentName($p['department_id']) ?: null,
            'employment' => str_replace('-', ' ', (string) $p['employment_type']), 'based_in' => $p['location_city'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<string>
     */
    private function skillNames(array $p): array
    {
        return Rows::of($p['skills'])->map(fn ($s) => (string) ($this->workspace->skills()->get($s['skill_id'])['name'] ?? ''))->values()->all();
    }

    /**
     * @return array{allowed: bool, results: list<array<string, mixed>>}
     */
    public function searchDirectory(string $text): array
    {
        $allowed = $this->viewer->can('directory.view');
        $this->record('search_directory', ['text' => $text], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'results' => []];
        }

        $needle = mb_strtolower($text);
        $contact = fn (array $row): ?string => ($c = Rows::of($row['contacts'] ?? [])->firstWhere('is_primary', true)) ? $c['name'].', '.$c['role'].' ('.$c['email'].')' : null;
        $match = fn (string ...$parts): bool => str_contains(mb_strtolower(implode(' ', $parts)), $needle);

        $results = array_slice([
            ...$this->workspace->clients()->filter(fn ($c) => $match($c['name'], (string) $c['sector']))->map(fn ($c) => array_filter(['kind' => 'client', 'name' => $c['name'], 'sector' => $c['sector'], 'account_lead' => $this->workspace->personName($c['account_lead_id']) ?: null, 'primary_contact' => $contact($c)]))->values()->all(),
            ...$this->workspace->suppliers()->filter(fn ($s) => $match($s['name'], str_replace('-', ' ', $s['category'])))->map(fn ($s) => array_filter(['kind' => 'supplier', 'name' => $s['name'], 'category' => str_replace('-', ' ', $s['category']), 'approved' => $s['is_approved'], 'rating' => $s['rating'], 'primary_contact' => $contact($s)], fn ($v) => $v !== null))->values()->all(),
            ...$this->workspace->venues()->filter(fn ($v) => $match($v['name'], $v['city'], $v['country']))->map(fn ($v) => ['kind' => 'venue', 'name' => $v['name'], 'city' => $v['city'], 'country' => $v['country'], 'capacity' => $v['capacity'], 'access' => $v['access_notes']])->values()->all(),
        ], 0, 15);

        return ['allowed' => true, 'results' => $results];
    }

    /**
     * @return array{allowed: bool, purchase_orders: list<array<string, mixed>>}
     */
    public function listPurchaseOrders(?string $code = null, ?string $status = null): array
    {
        $allowed = $this->viewer->can('po.view');
        $this->record('list_purchase_orders', ['event_code' => $code, 'status' => $status], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'purchase_orders' => []];
        }

        $event = $code ? $this->workspace->eventByCode($code) : null;
        $pos = $this->workspace->purchaseOrders()
            ->when($code, fn ($c) => $c->where('event_id', $event['id'] ?? 0))
            ->when($status, fn ($c) => $c->where('status', $status))
            ->filter(fn ($po) => ($e = $this->workspace->event($po['event_id'])) && $this->viewer->canSeeBudget($e))
            ->take(25)
            ->map(fn ($po) => [
                'code' => $po['code'], 'event' => $this->workspace->event($po['event_id'])['code'], 'supplier' => $this->workspace->supplierName($po['supplier_id']),
                'status' => $po['status'], 'total' => Money::of($po['total_minor'], $po['currency'])->format(false),
                'goods_received' => $po['goods_received'],
            ])->values()->all();

        return ['allowed' => true, 'purchase_orders' => $pos];
    }

    /**
     * @return array{allowed: bool, invoices: list<array<string, mixed>>}
     */
    public function listSupplierInvoices(?string $code = null, ?string $status = null): array
    {
        $allowed = $this->viewer->can('supplier_invoices.view');
        $this->record('list_supplier_invoices', ['event_code' => $code, 'status' => $status], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'invoices' => []];
        }

        $event = $code ? $this->workspace->eventByCode($code) : null;
        $invoices = $this->workspace->supplierInvoices()
            ->when($code, fn ($c) => $c->where('event_id', $event['id'] ?? 0))
            ->when($status, fn ($c) => $c->where('status', $status))
            ->filter(fn ($i) => $i['event_id'] === null || (($e = $this->workspace->event($i['event_id'])) && $this->viewer->canSeeBudget($e)))
            ->sortByDesc('invoice_date')
            ->take(25)
            ->map(fn ($i) => array_filter([
                'invoice_number' => $i['invoice_number'], 'supplier' => $this->workspace->supplierName($i['supplier_id']),
                'event' => $i['event_id'] ? $this->workspace->event($i['event_id'])['code'] : null,
                'gross' => Money::of($i['gross_minor'], $i['currency'])->format(false), 'status' => $i['status'],
                'due' => Display::date($i['due_date']), 'over_po_tolerance' => $this->workspace->invoiceOverTolerance($i) ?: null,
            ], fn ($v) => $v !== null))->values()->all();

        return ['allowed' => true, 'invoices' => $invoices];
    }

    /**
     * @return array{allowed: bool, expenses: list<array<string, mixed>>}
     */
    public function listMyExpenses(): array
    {
        $allowed = $this->viewer->personId() !== null && $this->viewer->can('expenses.submit');
        $this->record('list_my_expenses', [], $allowed);

        if (! $allowed) {
            return ['allowed' => false, 'expenses' => []];
        }

        $expenses = $this->workspace->expenses()->where('person_id', $this->viewer->personId())->sortByDesc('date')->take(20)
            ->map(fn ($x) => array_filter([
                'date' => Display::date($x['date']), 'category' => $x['category'], 'description' => $x['description'],
                'amount' => Money::of($x['amount_minor'], $x['currency'])->format(false), 'miles' => $x['mileage_miles'],
                'event' => $x['event_id'] ? ($this->workspace->event($x['event_id'])['code'] ?? null) : null,
                'status' => $x['status'], 'comment' => $x['decision_comment'],
            ], fn ($v) => $v !== null))->values()->all();

        return ['allowed' => true, 'expenses' => $expenses];
    }

    /**
     * @return array{allowed: bool, notifications: list<array<string, mixed>>}
     */
    public function listMyNotifications(): array
    {
        $this->record('list_my_notifications', [], true);

        $notes = $this->workspace->notificationsFor($this->viewer->personId())->take(10)
            ->map(fn ($n) => ['title' => $n['title'], 'detail' => $n['body'], 'when' => Display::dateTime($n['at']), 'read' => $n['read']])
            ->values()->all();

        return ['allowed' => true, 'notifications' => $notes];
    }

    /**
     * @return array{allowed: bool, items: list<array<string, mixed>>}
     */
    public function listMyChecklistItems(): array
    {
        $this->record('list_my_checklist_items', [], true);
        $user = $this->viewer->user;

        $items = Checklist::query()->with(['items', 'person'])->whereNull('completed_at')->get()
            ->flatMap(fn (Checklist $c) => $c->items
                ->filter(fn ($i) => $i->completed_at === null && $i->owner->canTick($user, $c->person))
                ->map(fn ($i) => array_filter([
                    'item_id' => $i->id, 'person' => $this->workspace->personName($c->person_id), 'checklist' => $c->kind->label(),
                    'item' => $i->title, 'owner' => $i->owner->label(), 'due' => $i->due_date ? Display::date($i->due_date) : null,
                ])))
            ->take(30)->values()->all();

        return ['allowed' => true, 'items' => $items];
    }

    /**
     * @return array{allowed: bool, reports: list<array<string, mixed>>}
     */
    public function listReports(): array
    {
        $reports = Reports::for($this->viewer->user);
        $this->record('list_reports', [], $reports !== []);

        return ['allowed' => $reports !== [], 'reports' => array_map(fn ($r) => [
            'key' => $r::key(), 'title' => $r->title(), 'description' => $r->description(),
            'filters' => array_map(fn ($f) => $f['type'] === 'select' ? $f['label'].': '.implode(', ', array_keys($f['options'] ?? [])) : $f['label'].' (YYYY-MM-DD)', $r->filters()),
        ], $reports)];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{allowed: bool, report?: string, filters?: array<string, string>, rows: list<array<string, string>>, total_rows?: int}
     */
    public function runReport(string $key, array $filters = []): array
    {
        $report = Reports::find($this->viewer->user, $key);
        $this->record('run_report', ['key' => $key, 'filters' => $filters], $report !== null);

        if ($report === null) {
            return ['allowed' => false, 'rows' => []];
        }

        $resolved = $report->resolve(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $filters));
        $rows = $report->rows($resolved);
        $columns = $report->columns();

        return [
            'allowed' => true, 'report' => $report->title(), 'filters' => $resolved, 'total_rows' => count($rows),
            'rows' => array_map(fn ($row) => collect($columns)->mapWithKeys(fn ($c) => [$c['label'] => $report->format($row[$c['key']] ?? null, $c['type'] ?? 'text')])->all(), array_slice($rows, 0, 25)),
        ];
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
