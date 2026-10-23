<?php

namespace App\Livewire\Time;

use App\Actions\People\DecideLeaveRequest;
use App\Actions\Time\ApproveTimesheetEntry;
use App\Actions\Time\ReturnTimesheetEntry;
use App\Livewire\Concerns\UsesWorkspace;
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

    #[Url(except: 'timesheets')]
    public string $tab = 'timesheets';

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

    public function render(): View
    {
        $insights = $this->insights();
        $groups = $insights->pendingEntries();
        $decide = app(DecideLeaveRequest::class);

        return view('livewire.time.approvals', [
            'groups' => $groups,
            'entryCount' => $groups->sum(fn ($g) => count($g['items'])),
            'invoices' => $insights->invoicesAwaitingApproval(),
            'leave' => $insights->leaveAwaitingApproval()->map(fn ($l) => $l + ['clashes' => $decide->clashingBookings($l['id'])]),
        ]);
    }
}
