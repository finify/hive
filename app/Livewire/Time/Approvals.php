<?php

namespace App\Livewire\Time;

use App\Actions\Approvals\DelegateApprovals;
use App\Actions\People\DecideLeaveRequest;
use App\Actions\Time\ApproveTimesheetEntry;
use App\Actions\Time\ReturnTimesheetEntry;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\ApprovalDelegation;
use App\Models\Expense;
use App\Models\FreelancerInvoice;
use App\Models\PurchaseOrder;
use App\Services\Approvals\ApprovalEngine;
use App\Support\DateRange;
use App\Support\Display;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "My approvals": everything waiting for the signed-in user, oldest first.
 */
#[Layout('layouts.app')]
#[Title('Approvals')]
class Approvals extends Component
{
    use UsesWorkspace;

    #[Url(except: 'all')]
    public string $tab = 'all';

    /** @var array{delegate_id: string, starts_on: string, ends_on: string, reason: string} */
    public array $cover = ['delegate_id' => '', 'starts_on' => '', 'ends_on' => '', 'reason' => ''];

    public ?int $returningEntry = null;

    public string $returnComment = '';

    public function approve(int $entryId, ApproveTimesheetEntry $approve): void
    {
        $this->attempt(fn () => $approve->handle(auth()->user(), $entryId), 'Entry approved. Crew cost posted to the event budget.');
    }

    /**
     * @param  list<int>  $entryIds
     */
    public function approveMany(array $entryIds, ApproveTimesheetEntry $approve): void
    {
        $count = 0;

        foreach ($entryIds as $id) {
            try {
                $approve->handle(auth()->user(), (int) $id);
                $count++;
            } catch (ValidationException) {
                continue;
            }
        }

        $this->toast($count === 1 ? '1 entry approved.' : "{$count} entries approved. Crew costs posted to the event budget.");
    }

    public function startReturn(int $entryId): void
    {
        $this->returningEntry = $entryId;
        $this->returnComment = '';
        $this->resetValidation();
        $this->dispatch('modal', id: 'return-modal', show: true);
    }

    public function confirmReturn(ReturnTimesheetEntry $return): void
    {
        $this->resetValidation();
        try {
            $return->handle(auth()->user(), (int) $this->returningEntry, $this->returnComment);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->dispatch('modal', id: 'return-modal', show: false);
        $this->toast('Returned with your comment.');
    }

    public function decideLeave(int $leaveId, bool $approve, DecideLeaveRequest $decide): void
    {
        $this->attempt(fn () => $decide->handle(auth()->user(), $leaveId, $approve), $approve ? 'Leave approved.' : 'Leave declined.');
    }

    public function delegate(DelegateApprovals $delegate): void
    {
        $this->resetValidation();

        try {
            $delegate->handle(auth()->user(), $this->cover);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['cover.'.$k => $m])->all());

            return;
        }

        $this->cover = ['delegate_id' => '', 'starts_on' => '', 'ends_on' => '', 'reason' => ''];
        $this->toast('Cover set. They have been told.');
    }

    public function endCover(int $id, DelegateApprovals $delegate): void
    {
        $this->attempt(fn () => $delegate->cancel(auth()->user(), $id), 'Cover ended.');
    }

    public function render(DelegateApprovals $delegate): View
    {
        $insights = $this->insights();
        $workspace = $this->workspace();
        $groups = $insights->pendingEntries();
        $decide = app(DecideLeaveRequest::class);
        $personId = auth()->user()->person_id;
        $invoices = $insights->invoicesAwaitingApproval();
        $leave = $insights->leaveAwaitingApproval()->map(fn ($l) => $l + ['clashes' => $decide->clashingBookings($l['id'])]);

        // Everything in one list, oldest first.
        $everything = collect();

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $everything->push([
                    'since' => $item['waiting_since'], 'type' => 'Timesheet', 'tab' => 'timesheets',
                    'title' => $workspace->personName($item['sheet']['person_id']).', '.Display::date($item['entry']['date']).', '.Display::hours($item['entry']['hours']),
                    'detail' => $group['event'] ? $group['event']['code'] : $item['entry']['internal_code'],
                    'for' => $item['delegated_from'] ? $workspace->personName($item['delegated_from']) : null,
                ]);
            }
        }

        foreach ($leave as $l) {
            $everything->push([
                'since' => $l['requested_at'] ?? $l['start_date'], 'type' => 'Leave', 'tab' => 'leave',
                'title' => $workspace->personName($l['person_id']).', '.DateRange::of($l['start_date'], $l['end_date'])->format(),
                'detail' => Display::days($l['days']), 'for' => null,
            ]);
        }

        foreach ($invoices as $si) {
            $everything->push([
                'since' => $si['invoice_date'], 'type' => 'Supplier invoice', 'tab' => 'invoices',
                'title' => $si['invoice_number'].', '.($workspace->suppliers()->get($si['supplier_id'])['name'] ?? ''),
                'detail' => $workspace->event($si['event_id'])['code'] ?? '', 'for' => null,
            ]);
        }

        // Purchase orders, expenses and freelancer invoices, from the approvals engine.
        $other = ['po' => collect(), 'expense' => collect(), 'freelancer' => collect()];

        foreach ($personId ? app(ApprovalEngine::class)->inbox($personId) : [] as $approval) {
            $for = $approval->approver_id !== $personId ? $workspace->personName($approval->approver_id) : null;

            if ($approval->approvable_type === PurchaseOrder::class && ($po = $workspace->purchaseOrders()->get($approval->approvable_id))) {
                $other['po']->push(['since' => $approval->created_at, 'type' => 'Purchase order', 'tab' => 'purchase-orders', 'href' => route('purchase-orders.show', $po['code']),
                    'title' => $po['code'].', '.$workspace->supplierName($po['supplier_id']).', '.Money::of($po['subtotal_minor'], $po['currency'])->format(),
                    'detail' => ($workspace->event($po['event_id'])['code'] ?? '').($approval->step > 1 ? ', second signature' : ''), 'for' => $for]);
            } elseif ($approval->approvable_type === Expense::class && ($x = $workspace->expenses()->get($approval->approvable_id))) {
                $other['expense']->push(['since' => $approval->created_at, 'type' => 'Expense', 'tab' => 'expenses', 'href' => route('expenses'),
                    'title' => $workspace->personName($x['person_id']).', '.Money::of($x['amount_minor'], $x['currency'])->format().', '.$x['description'],
                    'detail' => ($workspace->event($x['event_id'])['code'] ?? 'No event').($approval->step > 1 ? ', producer approved' : ''), 'for' => $for]);
            } elseif ($approval->approvable_type === FreelancerInvoice::class && ($fi = FreelancerInvoice::query()->find($approval->approvable_id))) {
                $other['freelancer']->push(['since' => $approval->created_at, 'type' => 'Freelancer invoice', 'tab' => 'freelancer-invoices', 'href' => route('freelancer-invoices'),
                    'title' => $fi->invoice_number.', '.$workspace->personName($fi->person_id).', '.Money::of($fi->net_minor)->format(),
                    'detail' => $fi->difference() === 0 ? 'Matches approved time' : Money::of(abs($fi->difference()))->format().($fi->difference() > 0 ? ' over' : ' under').' approved time', 'for' => $for]);
            }
        }

        $everything = $everything->merge($other['po'])->merge($other['expense'])->merge($other['freelancer']);

        return view('livewire.time.approvals', [
            'other' => $other,
            'groups' => $groups,
            'entryCount' => $groups->sum(fn ($g) => count($g['items'])),
            'invoices' => $invoices,
            'leave' => $leave,
            'everything' => $everything->sortBy(fn ($r) => (string) ($r['since'] instanceof \DateTimeInterface ? $r['since']->format('Y-m-d H:i') : $r['since']))->values(),
            'candidates' => $personId ? $delegate->candidates(auth()->user()) : collect(),
            'myCover' => $personId ? ApprovalDelegation::query()->whereNull('cancelled_at')->where('person_id', $personId)->whereDate('ends_on', '>=', $workspace->today()->toDateString())->orderBy('starts_on')->get() : collect(),
            'coveringFor' => $personId ? ApprovalDelegation::query()->activeOn($workspace->today()->toDateString())->where('delegate_id', $personId)->get() : collect(),
        ]);
    }
}
