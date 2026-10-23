<?php

namespace App\Demo;

use App\Data\Clash;
use App\Data\EventFinancials;
use App\Enums\DocumentStatus;
use App\Enums\EventStage;
use App\Enums\HealthStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Models\LeaveRequest;
use App\Models\TimesheetEntry;
use App\Services\Approvals\ApprovalEngine;
use App\Support\DateRange;
use App\Support\Display;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Aggregates for dashboards and Ask Hive, always computed through a Viewer so
 * nobody sees a figure built from records they may not see.
 */
class Insights
{
    private readonly ApprovalEngine $approvals;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly Viewer $viewer,
        ?ApprovalEngine $approvals = null,
    ) {
        $this->approvals = $approvals ?? app(ApprovalEngine::class);
    }

    /**
     * Fee value of open enquiries and proposals, by stage.
     *
     * @return array<string, array{count: int, value: Money}>
     */
    public function pipelineByStage(): array
    {
        $out = [];

        foreach ([EventStage::Enquiry, EventStage::ProposalSent, EventStage::Confirmed, EventStage::PreProduction, EventStage::Live] as $stage) {
            $events = $this->viewer->events()->where('stage', $stage->value);
            $out[$stage->label()] = [
                'count' => $events->count(),
                'value' => Money::sum($events->map(fn ($e) => Money::of((int) ($e['client_fee_minor'] ?? 0)))),
            ];
        }

        return $out;
    }

    public function pipelineValue(): Money
    {
        return Money::sum($this->viewer->events()
            ->whereIn('stage', [EventStage::Enquiry->value, EventStage::ProposalSent->value])
            ->map(fn ($e) => Money::of((int) ($e['client_fee_minor'] ?? 0))));
    }

    /**
     * Confirmed client fees by the month the event starts, for the next six months.
     *
     * @return array{labels: list<string>, values: list<float>, highlight: int}
     */
    public function confirmedRevenueByMonth(): array
    {
        $start = $this->workspace->today()->startOfMonth()->subMonths(2);
        $labels = [];
        $values = [];

        for ($i = 0; $i < 8; $i++) {
            $month = $start->addMonths($i);
            $labels[] = $month->format('M y');
            $fees = $this->viewer->events()
                ->filter(fn ($e) => EventStage::from($e['stage'])->isConfirmedOrLater() && str_starts_with((string) $e['start_date'], $month->format('Y-m')))
                ->sum(fn ($e) => (int) ($e['client_fee_minor'] ?? 0));
            $values[] = round($fees / 100, 2);
        }

        return ['labels' => $labels, 'values' => $values, 'highlight' => 2];
    }

    /**
     * Forecast margin across active events the viewer may see the budgets of.
     *
     * @return array{revenue: Money, forecast: Money, margin: Money, percent: ?float, events: int}
     */
    public function forecastMargin(): array
    {
        $revenue = Money::zero();
        $forecast = Money::zero();
        $count = 0;

        foreach ($this->activeEventsWithBudgets() as $event) {
            $f = $this->workspace->financials($event['id']);

            if ($f) {
                $revenue = $revenue->plus($f->revenue);
                $forecast = $forecast->plus($f->forecast());
                $count++;
            }
        }

        $margin = $revenue->minus($forecast);

        return [
            'revenue' => $revenue,
            'forecast' => $forecast,
            'margin' => $margin,
            'percent' => $revenue->isZero() ? null : round($margin->minor * 100 / $revenue->minor, 1),
            'events' => $count,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function activeEventsWithBudgets(): Collection
    {
        $active = [EventStage::Confirmed, EventStage::PreProduction, EventStage::Live, EventStage::Wrap];

        return $this->viewer->events()
            ->filter(fn ($e) => in_array(EventStage::from($e['stage']), $active, true))
            ->filter(fn ($e) => $this->viewer->canSeeBudget($e))
            ->sortBy('start_date');
    }

    /**
     * @return Collection<int, array{event: array<string, mixed>, health: HealthStatus, financials: EventFinancials}>
     */
    public function eventsNeedingAttention(): Collection
    {
        $rows = [];

        foreach ($this->activeEventsWithBudgets() as $event) {
            $financials = $this->workspace->financials($event['id']);
            $health = $financials?->health;

            if ($financials && ($health === HealthStatus::AtRisk || $health === HealthStatus::Watch)) {
                $rows[] = ['event' => $event, 'health' => $health, 'financials' => $financials];
            }
        }

        usort($rows, fn ($a, $b) => ($a['health'] === HealthStatus::AtRisk ? 0 : 1) <=> ($b['health'] === HealthStatus::AtRisk ? 0 : 1));

        return new Collection($rows);
    }

    /**
     * Booked days over working days this month, per department.
     *
     * @return list<array{label: string, value: float}>
     */
    public function utilisationByDepartment(): array
    {
        $today = $this->workspace->today();
        $range = DateRange::of($today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString());
        $rows = [];

        foreach ($this->workspace->departments() as $department) {
            // Staff, plus freelancers who worked for the agency in the period.
            $people = $this->workspace->people()->where('department_id', $department['id'])
                ->filter(fn ($p) => $p['employment_type'] !== 'freelancer' || $this->workspace->utilisation($p['id'], $range) > 0);

            if ($people->isEmpty()) {
                continue;
            }

            $total = $people->sum(fn ($p) => $this->workspace->utilisation($p['id'], $range));
            $rows[] = ['label' => $department['name'], 'value' => round($total / $people->count(), 3)];
        }

        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);

        return $rows;
    }

    /**
     * Timesheet entries waiting for this viewer's decision, grouped by event or code.
     *
     * @return Collection<string, array{label: string, event: ?array<string, mixed>, items: list<array{sheet: array<string, mixed>, entry: array<string, mixed>, flags: list<array{code: string, message: string}>, cost: ?Money, waiting_since: Carbon, delegated_from: ?int}>}>
     */
    public function pendingEntries(): Collection
    {
        $groups = collect();
        $personId = $this->viewer->personId();

        if ($personId === null) {
            return $groups;
        }

        $flagsBySheet = [];

        foreach ($this->approvals->inbox($personId)->where('approvable_type', TimesheetEntry::class) as $approval) {
            $found = $this->workspace->findEntry($approval->approvable_id);

            if ($found === null) {
                continue;
            }

            [$sheet, $entry] = $found;

            if ($entry['status'] !== 'pending' || in_array($sheet['status'], ['draft', 'locked'], true)) {
                continue;
            }

            $flags = $flagsBySheet[$sheet['id']] ??= $this->workspace->entryFlags($sheet);
            $event = $this->workspace->event($entry['event_id']);
            $key = $event ? $event['code'] : 'internal-'.$entry['internal_code'];

            $group = $groups->get($key, [
                'label' => $event ? $event['name'] : "{$entry['internal_code']} (internal)",
                'event' => $event,
                'items' => [],
            ]);
            $group['items'][] = [
                'sheet' => $sheet, 'entry' => $entry, 'flags' => $flags[$entry['id']] ?? [],
                'cost' => $this->viewer->canSeeRatesOf($sheet['person_id']) ? $this->workspace->entryCost($sheet['person_id'], $entry) : null,
                'waiting_since' => $approval->created_at, 'delegated_from' => $approval->approver_id !== $personId ? $approval->approver_id : null,
            ];
            $groups->put($key, $group);
        }

        return $groups;
    }

    public function pendingEntryCount(): int
    {
        return $this->pendingEntries()->sum(fn ($g) => count($g['items']));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function invoicesAwaitingApproval(): Collection
    {
        return $this->workspace->supplierInvoices()
            ->filter(fn ($si) => $this->viewer->user->can('supplier-invoice.approve', [$si]))
            ->sortBy('invoice_date')
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function leaveAwaitingApproval(): Collection
    {
        $personId = $this->viewer->personId();

        if ($personId === null) {
            return collect();
        }

        $ids = $this->approvals->inbox($personId)->where('approvable_type', LeaveRequest::class)->pluck('approvable_id')->all();

        return Rows::of($this->workspace->leaveRequests()
            ->filter(fn ($l) => in_array($l['id'], $ids, true) && $this->viewer->user->can('leave.approve-request', [$l]))
            ->sortBy('start_date')
            ->values()
            ->all());
    }

    /**
     * Open clashes the viewer may see: all of them with rota.view_all,
     * otherwise only their own.
     *
     * @return list<Clash>
     */
    public function clashes(): array
    {
        return array_values(array_filter(
            $this->workspace->clashes(),
            fn ($c) => $this->viewer->canSeeWholeRota() || $this->viewer->isSelf($c->personId),
        ));
    }

    /**
     * Booking requests waiting for this person's answer.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function myBookingRequests(): Collection
    {
        return $this->workspace->assignments()
            ->filter(fn ($a) => $this->viewer->user->can('assignment.respond', [$a]))
            ->values();
    }

    /**
     * A single list across modules, most urgent first.
     *
     * @return list<array{rank: int, tone: string, title: string, detail: string, href: string, action: string}>
     */
    public function needsAttention(): array
    {
        $items = [];
        $today = $this->workspace->today();

        foreach ($this->clashes() as $clash) {
            $mine = collect($clash->eventIds())->contains(fn ($id) => $this->viewer->isProducerOf($this->workspace->event($id) ?? ['producer_id' => null]));

            if (! $mine && ! $this->viewer->can('crew.manage_all') && ! $this->viewer->isSelf($clash->personId)) {
                continue;
            }

            $codes = collect([$clash->first, $clash->second])->map(fn ($b) => $b->label)->implode(' and ');
            $items[] = [
                'rank' => $clash->dates->start->lessThanOrEqualTo($today->addDays(2)) ? 100 : 80,
                'tone' => 'negative',
                'title' => $this->workspace->personName($clash->personId).' is double-booked',
                'detail' => "{$codes} on ".$clash->dates->format(),
                'href' => route('clashes'),
                'action' => 'Resolve',
            ];
        }

        foreach ($this->myBookingRequests() as $request) {
            $event = $this->workspace->event($request['event_id']);
            $items[] = [
                'rank' => 90, 'tone' => 'warning',
                'title' => "Booking request: {$event['name']}",
                'detail' => DateRange::of($request['start_date'], $request['end_date'])->format().", {$event['city']}",
                'href' => route('my-work'), 'action' => 'Reply',
            ];
        }

        $pending = $this->pendingEntryCount();

        if ($pending > 0) {
            $flagged = $this->pendingEntries()->sum(fn ($g) => collect($g['items'])->filter(fn ($i) => $i['flags'] !== [])->count());
            $items[] = [
                'rank' => 70 + min(20, $pending), 'tone' => 'warning',
                'title' => $pending === 1 ? '1 timesheet entry to approve' : "{$pending} timesheet entries to approve",
                'detail' => $flagged ? "{$flagged} flagged for working time" : 'No working time flags',
                'href' => route('approvals'), 'action' => 'Review',
            ];
        }

        foreach ($this->invoicesAwaitingApproval() as $invoice) {
            if (! $this->workspace->invoiceOverTolerance($invoice) && $invoice['status'] !== SupplierInvoiceStatus::Matched->value) {
                continue;
            }

            $over = $this->workspace->invoiceOverTolerance($invoice);
            $items[] = [
                'rank' => $over ? 85 : 60, 'tone' => $over ? 'negative' : 'warning',
                'title' => "Invoice {$invoice['invoice_number']} ".($over ? 'is over tolerance' : 'is ready to approve'),
                'detail' => $this->workspace->suppliers()->get($invoice['supplier_id'])['name'].', '.($over ? Money::of($invoice['variance_minor'])->format().' above its PO' : Money::of($invoice['net_minor'], $invoice['currency'])->format()),
                'href' => route('supplier-invoices'), 'action' => 'Review',
            ];
        }

        foreach ($this->eventsNeedingAttention() as $row) {
            $f = $row['financials'];
            $items[] = [
                'rank' => $row['health'] === HealthStatus::AtRisk ? 88 : 50,
                'tone' => $row['health'] === HealthStatus::AtRisk ? 'negative' : 'warning',
                'title' => "{$row['event']['name']} is ".($row['health'] === HealthStatus::AtRisk ? 'at risk' : 'on watch'),
                'detail' => 'Forecast '.$f->forecast()->formatShort().' against a budget of '.$f->budget->formatShort().', margin '.($f->marginPercent() ?? '—').'%',
                'href' => route('events.show', [$row['event']['code'], 'tab' => 'budget']), 'action' => 'Open budget',
            ];
        }

        foreach ($this->leaveAwaitingApproval() as $leave) {
            $items[] = [
                'rank' => 55, 'tone' => 'warning',
                'title' => $this->workspace->personName($leave['person_id']).' asked for leave',
                'detail' => DateRange::of($leave['start_date'], $leave['end_date'])->format().', '.Display::days($leave['days']),
                'href' => route('approvals', ['tab' => 'leave']), 'action' => 'Decide',
            ];
        }

        if ($this->viewer->can('documents.view')) {
            foreach ($this->workspace->documents() as $doc) {
                $status = $this->workspace->documentStatus($doc);

                if ($status === DocumentStatus::Valid) {
                    continue;
                }

                $items[] = [
                    'rank' => $status === DocumentStatus::Expired ? 75 : 40, 'tone' => $status === DocumentStatus::Expired ? 'negative' : 'warning',
                    'title' => $this->workspace->personName($doc['person_id']).': '.mb_strtolower($doc['title']),
                    'detail' => ($status === DocumentStatus::Expired ? 'Expired ' : 'Expires ').Display::date($doc['expires_at']),
                    'href' => route('people.show', $doc['person_id']), 'action' => 'Open',
                ];
            }
        }

        if ($this->viewer->can('billing.view')) {
            foreach ($this->workspace->billingMilestones()->where('status', 'overdue') as $milestone) {
                $event = $this->workspace->event($milestone['event_id']);
                $items[] = [
                    'rank' => 78, 'tone' => 'negative',
                    'title' => "Client payment overdue: {$event['code']}",
                    'detail' => "{$milestone['description']}, ".Money::of($milestone['amount_minor'])->format().', due '.Display::date($milestone['due_date']),
                    'href' => route('billing'), 'action' => 'Open',
                ];
            }
        }

        foreach ($this->workspace->timesheets()->where('person_id', $this->viewer->personId()) as $sheet) {
            if ($sheet['status'] === 'returned') {
                $items[] = ['rank' => 82, 'tone' => 'negative', 'title' => 'A timesheet was returned to you', 'detail' => 'Week of '.Display::date($sheet['week_start']), 'href' => route('timesheets'), 'action' => 'Fix'];
            }

            if ($sheet['status'] === 'draft') {
                $items[] = ['rank' => 65, 'tone' => 'warning', 'title' => 'Submit this week’s timesheet', 'detail' => 'Week of '.Display::date($sheet['week_start']).', due by Monday', 'href' => route('timesheets'), 'action' => 'Open'];
            }
        }

        usort($items, fn ($a, $b) => $b['rank'] <=> $a['rank']);

        return array_slice($items, 0, 8);
    }
}
