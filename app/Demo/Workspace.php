<?php

namespace App\Demo;

use App\Data\Booking;
use App\Data\Clash;
use App\Data\EventFinancials;
use App\Enums\AssignmentStatus;
use App\Enums\BudgetStatus;
use App\Enums\DocumentStatus;
use App\Enums\EntryStatus;
use App\Enums\EventStage;
use App\Enums\HealthStatus;
use App\Enums\LeaveStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Models\Assignment;
use App\Models\BillingMilestone;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Client;
use App\Models\CostEntry;
use App\Models\CrewCall;
use App\Models\Department;
use App\Models\Document;
use App\Models\Event;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\LeaveRequest;
use App\Models\PeriodLock;
use App\Models\Person;
use App\Models\PurchaseOrder;
use App\Models\RevenueLine;
use App\Models\Skill;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Timesheet;
use App\Models\Unavailability;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\HiveNotice;
use App\Support\DateRange;
use App\Support\Finance\HealthCalculator;
use App\Support\Finance\InvoiceTolerance;
use App\Support\Finance\RateBook;
use App\Support\Money;
use App\Support\Resourcing\ClashDetector;
use App\Support\Resourcing\ShiftPattern;
use App\Support\Time\WorkingTimeRules;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * The agency: people and the directory from the database (phase 1), and the
 * modules still on demo JSON, with this session's demo changes applied.
 *
 * Queries here return everything; scope what a user sees with Viewer. In the
 * later build phases each section moves to Eloquent models with the same
 * shapes, so screens and Actions keep working.
 */
class Workspace
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        public readonly DemoData $data,
        public readonly DemoLedger $ledger,
    ) {}

    public function today(): CarbonImmutable
    {
        return $this->data->today();
    }

    public function forget(): void
    {
        $this->memo = [];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $build
     * @return T
     */
    private function memo(string $key, callable $build): mixed
    {
        return $this->memo[$key] ??= $build();
    }

    // ================================================================== organisation

    /**
     * @return array<string, mixed>
     */
    public function organisation(): array
    {
        return $this->data->raw('organisation');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function departments(): Collection
    {
        return $this->memo('departments', fn () => Rows::keyed(
            Department::query()->orderBy('id')->get()->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'head_person_id' => $d->head_person_id])->all(),
        ));
    }

    public function departmentName(?int $id): string
    {
        return $id ? ($this->departments()->get($id)['name'] ?? '—') : '—';
    }

    public function costCategoryName(string $key): string
    {
        return Rows::of($this->organisation()['cost_categories'])->firstWhere('key', $key)['name'] ?? ucfirst($key);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function leaveType(string $key): ?array
    {
        return Rows::of($this->organisation()['leave_types'])->firstWhere('key', $key);
    }

    // ================================================================== people and directory

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function people(): Collection
    {
        return $this->memo('people', fn () => Rows::keyed(
            Person::query()->with(['restricted', 'dayRates', 'skills'])->orderBy('id')->get()->map(fn (Person $p) => $p->toWorkspaceArray())->all(),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function person(?int $id): ?array
    {
        return $id ? $this->people()->get($id) : null;
    }

    public function personName(?int $id): string
    {
        $person = $this->person($id);

        if (! $person) {
            return '—';
        }

        return ($person['preferred_name'] ?: $person['first_name']).' '.$person['last_name'];
    }

    public function isFreelancer(?int $personId): bool
    {
        return ($this->person($personId)['employment_type'] ?? null) === 'freelancer';
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function skills(): Collection
    {
        return $this->memo('skills', fn () => Rows::keyed(
            Skill::query()->orderBy('id')->get()->map(fn (Skill $s) => ['id' => $s->id, 'name' => $s->name, 'category' => $s->category])->all(),
        ));
    }

    public function skillLevel(int $personId, ?int $skillId): int
    {
        if ($skillId === null) {
            return 0;
        }

        foreach ($this->person($personId)['skills'] ?? [] as $skill) {
            if ($skill['skill_id'] === $skillId) {
                return (int) $skill['level'];
            }
        }

        return 0;
    }

    public function rateBook(int $personId): RateBook
    {
        return new RateBook($this->person($personId)['day_rates'] ?? []);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function clients(): Collection
    {
        return $this->memo('clients', fn () => Rows::keyed(
            Client::query()->with('contacts')->orderBy('id')->get()->map(fn (Client $c) => $c->toWorkspaceArray())->all(),
        ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function suppliers(): Collection
    {
        return $this->memo('suppliers', fn () => Rows::keyed(
            Supplier::query()->with(['contacts', 'documents'])->orderBy('id')->get()->map(fn (Supplier $s) => $s->toWorkspaceArray())->all(),
        ));
    }

    public function supplierName(?int $id): string
    {
        return $this->suppliers()->get($id)['name'] ?? 'Unknown supplier';
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function venues(): Collection
    {
        return $this->memo('venues', fn () => Rows::keyed(
            Venue::query()->orderBy('id')->get()->map(fn (Venue $v) => $v->toWorkspaceArray())->all(),
        ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function documents(): Collection
    {
        return $this->memo('documents', fn () => Rows::keyed(
            Document::query()->where('documentable_type', Person::class)->orderBy('id')->get()->map(fn (Document $d) => [
                'id' => $d->id, 'person_id' => $d->documentable_id, 'type' => $d->type->value, 'title' => $d->title,
                'issued_at' => $d->issued_at?->toDateString(), 'expires_at' => $d->expires_at?->toDateString(),
                'mandatory' => $d->mandatory, 'has_file' => $d->file_path !== null, 'original_name' => $d->original_name,
            ])->all(),
        ));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function documentStatus(array $document): DocumentStatus
    {
        if ($document['expires_at'] === null) {
            return DocumentStatus::Valid;
        }

        $expires = CarbonImmutable::parse($document['expires_at']);

        if ($expires->lessThan($this->today())) {
            return DocumentStatus::Expired;
        }

        return $expires->lessThanOrEqualTo($this->today()->addDays(30)) ? DocumentStatus::Expiring : DocumentStatus::Valid;
    }

    /**
     * Mandatory documents (insurance, right to work) that have expired.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function expiredMandatoryDocuments(int $personId): Collection
    {
        return Rows::of($this->documents()
            ->where('person_id', $personId)
            ->filter(fn ($doc) => $doc['mandatory'] && $this->documentStatus($doc) === DocumentStatus::Expired)
            ->all());
    }

    // ================================================================== events

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function events(): Collection
    {
        return $this->memo('events', fn () => Rows::keyed(
            Event::query()->with(['phases', 'team', 'stageChanges', 'tasks', 'documents'])->orderBy('id')->get()
                ->map(fn (Event $e) => $e->toWorkspaceArray())->all(),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function event(?int $id): ?array
    {
        return $id ? $this->events()->get($id) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function eventByCode(string $code): ?array
    {
        return $this->events()->firstWhere('code', strtoupper($code));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function stage(array $event): EventStage
    {
        return EventStage::from($event['stage']);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function eventDates(array $event): DateRange
    {
        return DateRange::of($event['start_date'], $event['end_date']);
    }

    /**
     * The full span an event occupies, phases included.
     *
     * @param  array<string, mixed>  $event
     */
    public function eventSpan(array $event): DateRange
    {
        $start = $event['start_date'] ?? $this->today()->toDateString();
        $end = $event['end_date'] ?? $start;

        foreach (Rows::of($event['phases']) as $phase) {
            $start = min($start, $phase['start_date']);
            $end = max($end, $phase['end_date']);
        }

        return DateRange::of($start, $end);
    }

    public function isOnEvent(int $eventId, ?int $personId): bool
    {
        if ($personId === null) {
            return false;
        }

        $event = $this->event($eventId);

        if (! $event) {
            return false;
        }

        if (Rows::of($event['team'])->contains('person_id', $personId)) {
            return true;
        }

        return $this->assignments()->contains(fn ($a) => $a['event_id'] === $eventId && $a['person_id'] === $personId);
    }

    // ================================================================== resourcing

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function crewCalls(): Collection
    {
        return $this->memo('crew_calls', fn () => Rows::keyed(
            CrewCall::query()->orderBy('id')->get()->map(fn (CrewCall $c) => $c->toWorkspaceArray())->all(),
        ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function assignments(): Collection
    {
        return $this->memo('assignments', fn () => Rows::keyed(
            Assignment::query()->orderBy('id')->get()->map(fn (Assignment $a) => $a->toWorkspaceArray())->all(),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function assignment(int $id): ?array
    {
        return $this->assignments()->get($id);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function leaveRequests(): Collection
    {
        return $this->memo('leave', fn () => Rows::keyed(
            LeaveRequest::query()->orderBy('id')->get()->map(fn (LeaveRequest $l) => $l->toWorkspaceArray())->all(),
        ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function unavailability(): Collection
    {
        return $this->memo('unavailability', fn () => Rows::keyed(
            Unavailability::query()->orderBy('id')->get()->map(fn (Unavailability $u) => $u->toWorkspaceArray())->all(),
        ));
    }

    /**
     * Everything that holds a person: live assignments, approved leave and
     * marked unavailability.
     *
     * @return list<Booking>
     */
    public function bookings(): array
    {
        return $this->memo('bookings', function () {
            $bookings = [];

            foreach ($this->assignments() as $a) {
                if (! AssignmentStatus::from($a['status'])->holdsPerson()) {
                    continue;
                }

                $bookings[] = new Booking(
                    Booking::ASSIGNMENT, $a['id'], $a['person_id'], DateRange::of($a['start_date'], $a['end_date']),
                    eventId: $a['event_id'],
                    confirmed: $a['status'] === AssignmentStatus::Confirmed->value,
                    overrideReason: $a['clash_override_reason'] ?? null,
                    label: $this->event($a['event_id'])['code'] ?? '',
                );
            }

            foreach ($this->leaveRequests() as $leave) {
                if ($leave['status'] === LeaveStatus::Approved->value) {
                    $bookings[] = new Booking(Booking::LEAVE, $leave['id'], $leave['person_id'], DateRange::of($leave['start_date'], $leave['end_date']), label: 'Leave');
                }
            }

            foreach ($this->unavailability() as $u) {
                $bookings[] = new Booking(Booking::UNAVAILABLE, $u['id'], $u['person_id'], DateRange::of($u['start_date'], $u['end_date']), label: 'Unavailable');
            }

            return $bookings;
        });
    }

    /**
     * Open clashes, earliest first. Clashes entirely in the past are left out.
     *
     * @return list<Clash>
     */
    public function clashes(): array
    {
        return $this->memo('clashes', function () {
            $today = $this->today();
            $clashes = array_filter(
                (new ClashDetector)->detect($this->bookings()),
                fn (Clash $c) => ! $c->isOverridden() && $c->dates->end->greaterThanOrEqualTo($today),
            );
            usort($clashes, fn (Clash $a, Clash $b) => $a->dates->start <=> $b->dates->start);

            return $clashes;
        });
    }

    public function findClash(string $key): ?Clash
    {
        foreach ($this->clashes() as $clash) {
            if ($clash->key() === $key) {
                return $clash;
            }
        }

        return null;
    }

    /**
     * People who could take over a booking: same role, the required skill at
     * the required level, and nothing holding them on those dates. Ranked by
     * skill level, then rating, then day rate. People based in $preferCountry
     * come first, so a London build is offered London crew before Lisbon crew.
     *
     * @param  list<int>  $exclude
     * @return Collection<int, array{person: array<string, mixed>, skill_level: int, rate: ?Money, rating: ?float}>
     */
    public function availablePeople(string $role, DateRange $dates, ?int $skillId = null, int $minLevel = 0, ?string $country = null, array $exclude = [], ?string $preferCountry = null): Collection
    {
        $busy = [];

        foreach ($this->bookings() as $booking) {
            if ($booking->dates->overlaps($dates)) {
                $busy[$booking->personId] = true;
            }
        }

        return $this->people()
            ->filter(fn ($p) => $p['is_active'] && ! isset($busy[$p['id']]) && ! in_array($p['id'], $exclude, true))
            ->filter(fn ($p) => strcasecmp($p['job_title'], $role) === 0)
            ->filter(fn ($p) => $country === null || $p['location_country'] === $country)
            ->map(fn ($p) => [
                'person' => Rows::record($p),
                'skill_level' => $this->skillLevel($p['id'], $skillId),
                'rate' => $this->rateBook($p['id'])->rateOn($dates->start),
                'rating' => is_numeric($p['rating']) ? (float) $p['rating'] : null,
            ])
            ->filter(fn ($row) => $skillId === null || $row['skill_level'] >= max(1, $minLevel))
            ->sort(function ($a, $b) use ($preferCountry) {
                $local = fn ($row) => $preferCountry !== null && $row['person']['location_country'] === $preferCountry ? 1 : 0;

                return [$local($b), $b['skill_level'], $b['rating'] ?? 0, $a['rate'] === null ? PHP_INT_MAX : $a['rate']->minor]
                    <=> [$local($a), $a['skill_level'], $a['rating'] ?? 0, $b['rate'] === null ? PHP_INT_MAX : $b['rate']->minor];
            })
            ->values();
    }

    /**
     * Working time warnings for a person's live bookings, keyed by assignment
     * id. $proposed adds a booking that doesn't exist yet (key "new").
     *
     * @param  array{dates: DateRange, call_time: string, wrap_time: string}|null  $proposed
     * @return array<int|string, list<string>>
     */
    public function shiftWarnings(int $personId, ?array $proposed = null, ?int $ignoreAssignmentId = null): array
    {
        $bookings = $this->assignments()
            ->filter(fn ($a) => $a['person_id'] === $personId && $a['id'] !== $ignoreAssignmentId && AssignmentStatus::from($a['status'])->holdsPerson())
            ->map(function ($a) {
                $call = $this->crewCalls()->get($a['crew_call_id']);

                return ['id' => $a['id'], 'dates' => DateRange::of($a['start_date'], $a['end_date']), 'call_time' => $call['call_time'] ?? '09:00', 'wrap_time' => $call['wrap_time'] ?? '18:00'];
            })
            ->values()
            ->all();

        if ($proposed !== null) {
            $bookings[] = ['id' => 'new'] + $proposed;
        }

        return ShiftPattern::fromConfig()->warnings($bookings);
    }

    /**
     * Booked days divided by working days (Mon–Fri) over a range.
     */
    public function utilisation(int $personId, DateRange $range): float
    {
        $working = array_filter($range->dates(), fn ($d) => CarbonImmutable::parse($d)->isWeekday());
        $booked = [];

        foreach ($this->bookings() as $booking) {
            if ($booking->personId !== $personId || $booking->kind !== Booking::ASSIGNMENT) {
                continue;
            }

            foreach ($working as $day) {
                if ($booking->dates->contains($day)) {
                    $booked[$day] = true;
                }
            }
        }

        return count($working) ? count($booked) / count($working) : 0.0;
    }

    // ================================================================== time

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function timesheets(): Collection
    {
        return $this->memo('timesheets', fn () => Rows::keyed(
            Timesheet::query()->with('entries')->orderBy('id')->get()->map(fn (Timesheet $t) => $t->toWorkspaceArray())->all(),
        ));
    }

    /**
     * Active payroll locks, latest first.
     *
     * @return Collection<int, array{id: int, start: string, end: string}>
     */
    public function periodLocks(): Collection
    {
        return $this->memo('period_locks', fn () => PeriodLock::query()->active()->orderByDesc('period_end')->get()->toBase()
            ->map(fn (PeriodLock $l) => ['id' => $l->id, 'start' => $l->period_start->toDateString(), 'end' => $l->period_end->toDateString()]));
    }

    /**
     * The end of the latest locked period, for messages.
     */
    public function lockedUntil(): ?string
    {
        return $this->periodLocks()->max('end');
    }

    public function isLocked(string $date): bool
    {
        return $this->periodLocks()->contains(fn ($l) => $l['start'] <= $date && $date <= $l['end']);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null [timesheet, entry]
     */
    public function findEntry(int $entryId): ?array
    {
        foreach ($this->timesheets() as $sheet) {
            foreach ($sheet['entries'] as $entry) {
                if ($entry['id'] === $entryId) {
                    return [$sheet, $entry];
                }
            }
        }

        return null;
    }

    /**
     * Working time flags for every entry on a timesheet, keyed by entry id.
     *
     * @param  array<string, mixed>  $sheet
     * @return array<int, list<array{code: string, message: string}>>
     */
    public function entryFlags(array $sheet): array
    {
        $shifts = [];

        foreach ($sheet['entries'] as $entry) {
            $assigned = true;
            $callHours = null;

            if ($entry['event_id']) {
                $assignment = $this->assignments()->first(fn ($a) => $a['person_id'] === $sheet['person_id']
                    && $a['event_id'] === $entry['event_id']
                    && DateRange::of($a['start_date'], $a['end_date'])->contains($entry['date']));
                $assigned = $assignment !== null;

                if ($assignment) {
                    $call = $this->crewCalls()->get($assignment['crew_call_id']);
                    $callHours = $call ? WorkingTimeRules::workedMinutes($call['call_time'], $call['wrap_time'], 0) / 60 : null;
                }
            }

            $shifts[] = [
                'id' => $entry['id'], 'date' => $entry['date'], 'start_time' => $entry['start_time'], 'end_time' => $entry['end_time'],
                'break_minutes' => $entry['break_minutes'], 'assigned' => $assigned, 'call_hours' => $callHours,
            ];
        }

        return WorkingTimeRules::fromConfig()->flag($shifts);
    }

    /**
     * The cost of a freelancer's timesheet entry at the rate effective that day.
     *
     * @param  array<string, mixed>  $entry
     */
    public function entryCost(int $personId, array $entry): ?Money
    {
        if (! $this->isFreelancer($personId) || ! $entry['event_id']) {
            return null;
        }

        return $this->rateBook($personId)->costFor($entry['date'], (float) $entry['days']);
    }

    // ================================================================== finance

    /**
     * @return array<string, mixed>|null
     */
    public function budget(int $eventId): ?array
    {
        return $this->memo('budgets', function () {
            $lines = BudgetLine::query()->orderBy('sort_order')->orderBy('id')->get()->groupBy('event_id');
            $budgets = [];

            foreach (Budget::query()->orderBy('version')->get() as $version) {
                $budgets[$version->event_id] = [
                    'event_id' => $version->event_id, 'budget_id' => $version->id, 'version' => $version->version, 'status' => $version->status->value,
                    'approved_by' => $version->approved_by, 'approved_at' => $version->approved_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'baseline_gbp_minor' => $version->status === BudgetStatus::Draft ? null : $version->total_gbp_minor,
                    'lines' => ($lines->get($version->event_id) ?? collect())->map(fn (BudgetLine $l) => $l->toWorkspaceArray())->values()->all(),
                ];
            }

            return collect($budgets);
        })->get($eventId);
    }

    /**
     * Committed and actual costs from POs, invoices, approved time, expenses
     * and manual journals.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function costEntries(?int $eventId = null): Collection
    {
        $all = $this->memo('cost_entries', function () {
            return Rows::keyed(CostEntry::query()->orderBy('id')->get()->map(fn (CostEntry $c) => $c->toWorkspaceArray())->all());
        });

        return $eventId === null ? $all : $all->where('event_id', $eventId);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function revenueLines(): Collection
    {
        return $this->memo('revenue', fn () => Rows::keyed(
            RevenueLine::query()->orderBy('id')->get()->map(fn (RevenueLine $r) => $r->toWorkspaceArray())->all(),
        ));
    }

    /**
     * The latest rate finance has entered for a currency, as one unit in GBP.
     */
    public function exchangeRate(string $currency): ?string
    {
        if ($currency === 'GBP') {
            return '1';
        }

        return $this->memo('fx_'.$currency, fn () => ExchangeRate::query()->where('currency', $currency)
            ->whereDate('effective_on', '<=', $this->today()->toDateString())->orderByDesc('effective_on')->orderByDesc('id')->value('rate_to_gbp'));
    }

    /**
     * What a freelancer's approved event time in a period costs, at the rate
     * in force each day: what their invoice for the period should come to.
     */
    public function approvedTimeCost(int $personId, string $from, string $to): Money
    {
        $total = Money::zero();

        foreach ($this->timesheets()->where('person_id', $personId) as $sheet) {
            foreach ($sheet['entries'] as $entry) {
                if ($entry['status'] === EntryStatus::Approved->value && $entry['date'] >= $from && $entry['date'] <= $to) {
                    $cost = $this->entryCost($personId, $entry);
                    $total = $cost ? $total->plus($cost) : $total;
                }
            }
        }

        return $total;
    }

    public function revenue(int $eventId): Money
    {
        return Money::sum(
            $this->revenueLines()
                ->where('event_id', $eventId)
                ->map(fn ($r) => Money::of($r['amount_minor'], $r['currency'])),
        );
    }

    /**
     * Budget lines with their committed, actual and remaining amounts in GBP.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function budgetLines(int $eventId): Collection
    {
        $budget = $this->budget($eventId);

        if (! $budget) {
            return collect();
        }

        $entries = $this->costEntries($eventId);

        return Rows::of($budget['lines'])->map(function (array $line) use ($entries): array {
            $forLine = $entries->where('budget_line_id', $line['id']);
            $committed = Money::of((int) $forLine->where('kind', 'committed')->sum('amount_gbp_minor'));
            $actual = Money::of((int) $forLine->where('kind', 'actual')->sum('amount_gbp_minor'));

            // Crew time approved in Hive was part of the remaining estimate.
            $drawn = (int) $forLine->where('draws_down_remaining', true)->sum('amount_gbp_minor');
            $remaining = Money::of(max(0, $line['remaining_gbp_minor'] - $drawn));

            $line['budget'] = Money::of($line['budget_gbp_minor']);
            $line['original'] = $line['currency'] === 'GBP' ? null : Money::of($line['budget_minor'], $line['currency']);
            $line['committed'] = $committed;
            $line['actual'] = $actual;
            $line['remaining'] = $remaining;
            $line['forecast'] = $actual->plus($committed)->plus($remaining);

            return Rows::record($line);
        });
    }

    public function financials(int $eventId): ?EventFinancials
    {
        $event = $this->event($eventId);

        if (! $event || ! $this->budget($eventId)) {
            return null;
        }

        $lines = $this->budgetLines($eventId);
        $budget = Money::sum($lines->pluck('budget'));

        // Costs not on any line (an expense in a category the budget lacks) still count.
        $unallocated = $this->costEntries($eventId)->reject(fn ($c) => $c['budget_line_id'] !== null && $lines->contains('id', $c['budget_line_id']));
        $committed = Money::sum($lines->pluck('committed'))->plus(Money::of((int) $unallocated->where('kind', 'committed')->sum('amount_gbp_minor')));
        $actual = Money::sum($lines->pluck('actual'))->plus(Money::of((int) $unallocated->where('kind', 'actual')->sum('amount_gbp_minor')));
        $remaining = Money::sum($lines->pluck('remaining'));
        $revenue = $this->revenue($eventId);

        $health = in_array($event['stage'], [EventStage::Reconciled->value, EventStage::Closed->value, EventStage::Cancelled->value], true)
            ? null
            : HealthCalculator::fromConfig()->status($budget, $actual->plus($committed)->plus($remaining), $revenue);

        return new EventFinancials($revenue, $budget, $committed, $actual, $remaining, $health);
    }

    public function health(int $eventId): ?HealthStatus
    {
        return $this->financials($eventId)?->health;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function purchaseOrders(): Collection
    {
        return $this->memo('purchase_orders', fn () => Rows::keyed(
            PurchaseOrder::query()->with('lines')->orderBy('id')->get()->map(fn (PurchaseOrder $po) => $po->toWorkspaceArray())->all(),
        ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function supplierInvoices(): Collection
    {
        return $this->memo('supplier_invoices', fn () => Rows::keyed(
            SupplierInvoice::query()->orderBy('id')->get()->map(fn (SupplierInvoice $si) => $si->toWorkspaceArray())->all(),
        ));
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function invoiceOverTolerance(array $invoice): bool
    {
        $po = $this->purchaseOrders()->get($invoice['purchase_order_id']);

        if (! $po) {
            return true;
        }

        // Compared with what is left to invoice on the PO, so a second partial
        // invoice is checked against the balance, not the whole order.
        $invoiced = $this->supplierInvoices()
            ->filter(fn ($si) => $si['purchase_order_id'] === $po['id'] && $si['id'] !== $invoice['id'] && in_array($si['status'], [SupplierInvoiceStatus::Approved->value, SupplierInvoiceStatus::Paid->value], true))
            ->sum('net_minor');

        return InvoiceTolerance::fromConfig()->isOverTolerance(
            Money::of($po['subtotal_minor'] - (int) $invoiced, $po['currency']),
            Money::of($invoice['net_minor'], $invoice['currency']),
        );
    }

    /**
     * Why an event cannot yet move from Wrap to Reconciled.
     *
     * @return list<string>
     */
    public function reconcileBlockers(int $eventId): array
    {
        $blockers = [];

        $openPos = $this->purchaseOrders()->where('event_id', $eventId)
            ->filter(fn ($po) => PurchaseOrderStatus::from($po['status'])->isOpen());

        foreach ($openPos as $po) {
            $blockers[] = "{$po['code']} is still open (".PurchaseOrderStatus::from($po['status'])->label().')';
        }

        $unapproved = $this->supplierInvoices()->where('event_id', $eventId)
            ->filter(fn ($si) => ! in_array($si['status'], [SupplierInvoiceStatus::Approved->value, SupplierInvoiceStatus::Paid->value], true));

        foreach ($unapproved as $si) {
            $blockers[] = "Invoice {$si['invoice_number']} from {$this->suppliers()->get($si['supplier_id'])['name']} is not approved yet (".SupplierInvoiceStatus::from($si['status'])->label().')';
        }

        $pending = 0;

        foreach ($this->timesheets() as $sheet) {
            foreach ($sheet['entries'] as $entry) {
                if ($entry['event_id'] === $eventId && $entry['status'] !== EntryStatus::Approved->value) {
                    $pending++;
                }
            }
        }

        if ($pending > 0) {
            $blockers[] = $pending === 1 ? '1 timesheet entry is not approved' : "{$pending} timesheet entries are not approved";
        }

        $expenses = $this->expenses()->where('event_id', $eventId)->where('status', 'pending')->count();

        if ($expenses > 0) {
            $blockers[] = $expenses === 1 ? '1 expense is waiting for approval' : "{$expenses} expenses are waiting for approval";
        }

        return $blockers;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function billingMilestones(): Collection
    {
        return $this->memo('billing', fn () => Rows::keyed(
            BillingMilestone::query()->orderBy('id')->get()->map(fn (BillingMilestone $m) => $m->toWorkspaceArray())->all(),
        ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function expenses(): Collection
    {
        return $this->memo('expenses', fn () => Rows::keyed(
            Expense::query()->orderBy('id')->get()->map(fn (Expense $x) => $x->toWorkspaceArray())->all(),
        ));
    }

    // ================================================================== platform

    /**
     * A person's notices: the seeded ones from the demo data and those stored
     * for their sign-in.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function notificationsFor(?int $personId): Collection
    {
        if ($personId === null) {
            return collect();
        }

        $read = $this->ledger->section('notifications_read');

        $seeded = collect($this->data->raw('notifications'))
            ->where('person_id', $personId)
            ->map(function ($n) use ($read) {
                $n['key'] = md5($n['person_id'].$n['title'].$n['at']);
                $n['read'] = $n['read'] || isset($read[$n['key']]);

                return $n;
            });

        $user = User::query()->where('person_id', $personId)->first();
        $person = Person::query()->withTrashed()->find($personId);

        return $this->stored($person)->merge($user ? $this->stored($user) : collect())->merge($seeded)->sortByDesc('at')->values();
    }

    /**
     * Everything in a user's bell.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function inboxFor(User $user): Collection
    {
        return $user->person_id ? $this->notificationsFor($user->person_id) : $this->stored($user)->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function stored(User|Person|null $owner): Collection
    {
        if ($owner === null) {
            return collect();
        }

        return Rows::of($owner->notifications()->latest()->limit(50)->get()->toBase()->map(fn (DatabaseNotification $n) => [
            'key' => $n->id,
            'person_id' => $owner instanceof Person ? $owner->id : $owner->person_id,
            'type' => $n->data['type'] ?? 'notice',
            'title' => $n->data['title'] ?? 'Notice',
            'body' => $n->data['body'] ?? '',
            'link' => $n->data['link'] ?? 'dashboard',
            'at' => $n->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'read' => $n->read_at !== null,
            'stored' => true,
        ])->all());
    }

    /**
     * Sends a notice to a person's bell and email. Stored against the person,
     * so freelancers without a sign-in keep the history.
     */
    public function notify(?int $personId, string $type, string $title, string $body, string $link): void
    {
        $person = $personId ? Person::query()->find($personId) : null;

        if ($person === null) {
            return;
        }

        $person->notify(new HiveNotice($person->id, $type, $title, $body, $link));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function seededActivity(): array
    {
        return $this->data->raw('activity');
    }

    /**
     * @return array<string, list<string>>
     */
    public function askHiveSuggestions(): array
    {
        return $this->data->raw('ask_hive')['suggestions'];
    }
}
