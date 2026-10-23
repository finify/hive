<?php

namespace App\Demo;

use App\Data\Booking;
use App\Data\Clash;
use App\Data\EventFinancials;
use App\Enums\AssignmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\EntryStatus;
use App\Enums\EventStage;
use App\Enums\HealthStatus;
use App\Enums\LeaveStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Enums\TimesheetStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\Document;
use App\Models\Person;
use App\Models\Skill;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Venue;
use App\Support\DateRange;
use App\Support\Finance\HealthCalculator;
use App\Support\Finance\InvoiceTolerance;
use App\Support\Finance\RateBook;
use App\Support\Money;
use App\Support\Resourcing\ClashDetector;
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
        return $this->memo('events', function () {
            $changes = $this->ledger->section('events');

            return $this->data->collect('events')
                ->map(fn (array $event) => isset($changes[$event['id']]) ? array_merge($event, $changes[$event['id']]) : $event);
        });
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
        $start = $event['start_date'];
        $end = $event['end_date'];

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
        return $this->memo('crew_calls', fn () => $this->data->collect('crew_calls'));
    }

    /**
     * Assignments with demo changes applied, including any created during the demo.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function assignments(): Collection
    {
        return $this->memo('assignments', function () {
            $changes = $this->ledger->section('assignments');
            $base = $this->data->collect('assignments');

            foreach ($this->ledger->section('new_assignments') as $created) {
                $base->put($created['id'], $created);
            }

            return $base->map(fn (array $a) => isset($changes[$a['id']]) ? array_merge($a, $changes[$a['id']]) : $a);
        });
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
        return $this->memo('leave', function () {
            $changes = $this->ledger->section('leave');

            return $this->data->collect('leave_requests')
                ->map(fn (array $l) => isset($changes[$l['id']]) ? array_merge($l, $changes[$l['id']]) : $l);
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function unavailability(): Collection
    {
        return $this->memo('unavailability', fn () => $this->data->collect('unavailability'));
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
     * Timesheets with entry decisions from the demo applied and their status
     * derived from their entries.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function timesheets(): Collection
    {
        return $this->memo('timesheets', function () {
            $changes = $this->ledger->section('entries');
            $sheetChanges = $this->ledger->section('timesheets');
            $sheets = $this->data->collect('timesheets');

            foreach ($this->ledger->section('new_timesheets') as $created) {
                $sheets->put($created['id'], $created);
            }

            foreach ($this->ledger->section('new_entries') as $added) {
                $sheet = $sheets->get($added['timesheet_id']);

                if ($sheet) {
                    $sheet['entries'][] = $added['entry'];
                    $sheets->put($sheet['id'], $sheet);
                }
            }

            return $sheets->map(function (array $sheet) use ($changes, $sheetChanges) {
                foreach ($sheet['entries'] as $i => $entry) {
                    if (isset($changes[$entry['id']])) {
                        $sheet['entries'][$i] = array_merge($entry, $changes[$entry['id']]);
                    }
                }

                if (isset($sheetChanges[$sheet['id']])) {
                    $sheet = array_merge($sheet, $sheetChanges[$sheet['id']]);
                }

                $sheet['status'] = $this->deriveTimesheetStatus($sheet);

                return Rows::record($sheet);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $sheet
     */
    private function deriveTimesheetStatus(array $sheet): string
    {
        if (in_array($sheet['status'], [TimesheetStatus::Draft->value, TimesheetStatus::Locked->value], true)) {
            return $sheet['status'];
        }

        $statuses = Rows::of($sheet['entries'])->pluck('status');

        if ($statuses->contains(EntryStatus::Returned->value)) {
            return TimesheetStatus::Returned->value;
        }

        if ($statuses->every(fn ($s) => $s === EntryStatus::Approved->value)) {
            return TimesheetStatus::Approved->value;
        }

        return TimesheetStatus::Submitted->value;
    }

    /**
     * Timesheets on or before this date are locked after payroll.
     */
    public function lockedUntil(): ?string
    {
        return $this->organisation()['timesheets_locked_until'] ?? null;
    }

    public function isLocked(string $date): bool
    {
        $until = $this->lockedUntil();

        return $until !== null && $date <= $until;
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
        return $this->memo('budgets', fn () => $this->data->collect('budgets', 'event_id'))->get($eventId);
    }

    /**
     * Cost entries with demo changes applied (new actuals, released commitments).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function costEntries(?int $eventId = null): Collection
    {
        $all = $this->memo('cost_entries', function () {
            $removed = $this->ledger->section('removed_cost_entries');

            return $this->data->collect('cost_entries')
                ->reject(fn ($c) => isset($removed[$c['id']]))
                ->merge(collect($this->ledger->section('cost_entries'))->keyBy('id'));
        });

        return $eventId === null ? $all : $all->where('event_id', $eventId);
    }

    public function revenue(int $eventId): Money
    {
        return Money::sum(
            $this->memo('revenue', fn () => $this->data->collect('revenue_lines'))
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
        $demoActuals = collect($this->ledger->section('cost_entries'))->where('event_id', $eventId);

        return Rows::of($budget['lines'])->map(function (array $line) use ($entries, $demoActuals): array {
            $forLine = $entries->where('budget_line_id', $line['id']);
            $committed = Money::of((int) $forLine->where('kind', 'committed')->sum('amount_gbp_minor'));
            $actual = Money::of((int) $forLine->where('kind', 'actual')->sum('amount_gbp_minor'));

            // Crew time approved during the demo was part of the remaining estimate.
            $drawn = (int) $demoActuals->where('budget_line_id', $line['id'])->where('source.type', 'timesheet-entry')->sum('amount_gbp_minor');
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
        $committed = Money::sum($lines->pluck('committed'));
        $actual = Money::sum($lines->pluck('actual'));
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
        return $this->memo('purchase_orders', function () {
            $changes = $this->ledger->section('purchase_orders');

            return $this->data->collect('purchase_orders')
                ->map(fn (array $po) => isset($changes[$po['id']]) ? array_merge($po, $changes[$po['id']]) : $po);
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function supplierInvoices(): Collection
    {
        return $this->memo('supplier_invoices', function () {
            $changes = $this->ledger->section('invoices');

            return $this->data->collect('supplier_invoices')
                ->map(fn (array $si) => isset($changes[$si['id']]) ? array_merge($si, $changes[$si['id']]) : $si);
        });
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

        return InvoiceTolerance::fromConfig()->isOverTolerance(
            Money::of($po['subtotal_minor'], $po['currency']),
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

        return $blockers;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function billingMilestones(): Collection
    {
        return $this->memo('billing', fn () => $this->data->collect('billing_milestones'));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function expenses(): Collection
    {
        return $this->memo('expenses', fn () => $this->data->collect('expenses'));
    }

    // ================================================================== platform

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function notificationsFor(?int $personId): Collection
    {
        if ($personId === null) {
            return collect();
        }

        $read = $this->ledger->section('notifications_read');

        return collect($this->data->raw('notifications'))
            ->merge($this->ledger->section('notifications'))
            ->where('person_id', $personId)
            ->map(function ($n) use ($read) {
                $n['key'] = md5($n['person_id'].$n['title'].$n['at']);
                $n['read'] = $n['read'] || isset($read[$n['key']]);

                return $n;
            })
            ->sortByDesc('at')
            ->values();
    }

    /**
     * Everything in a user's bell: their stored notifications (document
     * reminders, from phase 1) and the demo notifications for their person.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function inboxFor(User $user): Collection
    {
        $stored = $user->notifications()->latest()->limit(50)->get()->toBase()->map(fn (DatabaseNotification $n) => [
            'key' => $n->id,
            'person_id' => $user->person_id,
            'type' => $n->data['type'] ?? 'notice',
            'title' => $n->data['title'] ?? 'Notice',
            'body' => $n->data['body'] ?? '',
            'link' => $n->data['link'] ?? 'dashboard',
            'at' => $n->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'read' => $n->read_at !== null,
            'stored' => true,
        ]);

        return $stored->merge($this->notificationsFor($user->person_id))->sortByDesc('at')->values();
    }

    public function notify(int $personId, string $type, string $title, string $body, string $link): void
    {
        $this->ledger->push('notifications', [
            'person_id' => $personId, 'type' => $type, 'title' => $title, 'body' => $body, 'link' => $link,
            'at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'read' => false,
        ]);
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
