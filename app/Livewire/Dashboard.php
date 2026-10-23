<?php

namespace App\Livewire;

use App\Enums\EventStage;
use App\Livewire\Concerns\UsesWorkspace;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    use UsesWorkspace;

    public function render(): View
    {
        $user = auth()->user();
        $viewer = $this->viewer();
        $insights = $this->insights();
        $workspace = $this->workspace();
        $today = $workspace->today();
        $personId = $user->person_id;

        $myEvents = $viewer->events()->filter(fn ($e) => $viewer->isProducerOf($e) && ! EventStage::from($e['stage'])->isTerminal())->sortBy('start_date');
        $myBookings = $workspace->assignments()
            ->where('person_id', $personId)
            ->filter(fn ($a) => in_array($a['status'], ['confirmed', 'first-hold', 'second-hold'], true) && $a['end_date'] >= $today->toDateString())
            ->sortBy('start_date')
            ->map(fn ($a) => ['assignment' => $a, 'event' => $workspace->event($a['event_id'])]);

        $openCrewCalls = $workspace->crewCalls()
            ->filter(fn ($c) => $myEvents->has($c['event_id']))
            ->map(function ($c) use ($workspace) {
                $filled = $workspace->assignments()->where('crew_call_id', $c['id'])->filter(fn ($a) => in_array($a['status'], ['confirmed', 'first-hold'], true))->count();

                return $c + ['filled' => $filled, 'event' => $workspace->event($c['event_id'])];
            })
            ->filter(fn ($c) => $c['filled'] < $c['quantity'] && $c['end_date'] >= $workspace->today()->toDateString())
            ->sortBy('start_date')
            ->values();

        $month = DateRange::of($today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString());
        $postedThisMonth = $workspace->costEntries()->filter(fn ($c) => str_starts_with((string) $c['posted_at'], $today->format('Y-m')));
        $visibleEventIds = $insights->activeEventsWithBudgets()->keys();

        return view('livewire.dashboard', [
            'today' => $today,
            'attention' => $insights->needsAttention(),
            'clashes' => $insights->clashes(),
            'pendingEntries' => $insights->pendingEntryCount(),
            'invoices' => $insights->invoicesAwaitingApproval(),
            'leave' => $insights->leaveAwaitingApproval(),
            'atRisk' => $insights->eventsNeedingAttention(),
            'myEvents' => $myEvents,
            'myBookings' => $myBookings,
            'openCrewCalls' => $openCrewCalls,
            'bookingRequests' => $insights->myBookingRequests(),
            'showLeadership' => $user->can('budgets.view_all') && $user->can('events.view_all') && $user->can('reports.view'),
            'showFinance' => $user->can('billing.view'),
            'showHr' => $user->can('people.manage'),
            'showDepartment' => $user->can('crew.manage') && ! $user->can('budgets.view') && $user->can('timesheets.approve'),
            'pipeline' => $insights->pipelineByStage(),
            'pipelineValue' => $insights->pipelineValue(),
            'margin' => $insights->forecastMargin(),
            'revenueByMonth' => $insights->confirmedRevenueByMonth(),
            'utilisation' => $insights->utilisationByDepartment(),
            'committedThisMonth' => Money::of((int) $postedThisMonth->where('kind', 'committed')->whereIn('event_id', $visibleEventIds)->sum('amount_gbp_minor')),
            'actualThisMonth' => Money::of((int) $postedThisMonth->where('kind', 'actual')->whereIn('event_id', $visibleEventIds)->sum('amount_gbp_minor')),
            'upcomingPayments' => $workspace->billingMilestones()
                ->filter(fn ($m) => in_array($m['status'], ['due', 'overdue', 'invoiced'], true))
                ->sortBy('due_date')->take(6),
            'pendingPos' => $workspace->purchaseOrders()->where('status', 'pending-approval'),
            'expiring' => $workspace->documents()->filter(fn ($d) => $workspace->documentStatus($d)->value !== 'valid')->sortBy('expires_at'),
            'starters' => $workspace->people()->filter(fn ($p) => $p['start_date'] >= $today->subDays(30)->toDateString() && $p['start_date'] <= $today->addDays(30)->toDateString()),
            'leaveBalance' => $personId ? collect($workspace->data->raw('leave_allowances'))->firstWhere('person_id', $personId) : null,
            'mySheets' => $workspace->timesheets()->where('person_id', $personId)->sortByDesc('week_start')->take(4),
            'myDocuments' => $workspace->documents()->where('person_id', $personId)->filter(fn ($d) => $workspace->documentStatus($d)->value !== 'valid'),
            'month' => $month,
        ]);
    }
}
