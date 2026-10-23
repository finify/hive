<?php

namespace App\Services\AskHive;

use App\Demo\Insights;
use App\Demo\Rows;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Enums\DocumentStatus;
use App\Enums\EventStage;
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
     * Tool definitions in the shape the Anthropic Messages API expects, for Phase 7.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public static function definitions(): array
    {
        $range = ['type' => 'object', 'properties' => ['start_date' => ['type' => 'string', 'format' => 'date'], 'end_date' => ['type' => 'string', 'format' => 'date']]];

        return [
            ['name' => 'search_events', 'description' => 'Find events the user can see by stage, client or text.', 'input_schema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string'], 'stage' => ['type' => 'string']]]],
            ['name' => 'get_event_summary', 'description' => 'Summary of one event by code, for example EV-26114.', 'input_schema' => ['type' => 'object', 'properties' => ['event_code' => ['type' => 'string']], 'required' => ['event_code']]],
            ['name' => 'find_available_people', 'description' => 'People free for a role between two dates, ranked by skill and rating.', 'input_schema' => ['type' => 'object', 'properties' => ['role' => ['type' => 'string'], 'skill' => ['type' => 'string'], 'start_date' => ['type' => 'string'], 'end_date' => ['type' => 'string']], 'required' => ['role', 'start_date', 'end_date']]],
            ['name' => 'list_clashes', 'description' => 'Double bookings in a date range.', 'input_schema' => $range],
            ['name' => 'get_budget_health', 'description' => 'Budget, forecast and margin for active events, or one event. Needs budget access.', 'input_schema' => ['type' => 'object', 'properties' => ['event_code' => ['type' => 'string']]]],
            ['name' => 'list_pending_approvals', 'description' => 'Items waiting for the user to approve.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass]],
            ['name' => 'get_leave_calendar', 'description' => 'Who is away in a date range. Shows "Absent" for sensitive leave.', 'input_schema' => $range],
            ['name' => 'list_my_bookings', 'description' => 'The user’s own upcoming bookings.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass]],
        ];
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
