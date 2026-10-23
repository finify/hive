<?php

namespace App\Livewire\Time;

use App\Actions\Approvals\DelegateApprovals;
use App\Actions\People\DecideLeaveRequest;
use App\Actions\Time\ApproveTimesheetEntry;
use App\Actions\Time\ReturnTimesheetEntry;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\ApprovalDelegation;
use App\Support\DateRange;
use App\Support\Display;
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

        return view('livewire.time.approvals', [
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
