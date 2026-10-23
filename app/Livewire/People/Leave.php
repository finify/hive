<?php

namespace App\Livewire\People;

use App\Actions\People\RequestLeave;
use App\Livewire\Concerns\UsesWorkspace;
use App\Support\Display;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Leave')]
class Leave extends Component
{
    use UsesWorkspace;

    /** @var array{leave_type: string, start_date: string, end_date: string, reason: string} */
    public array $leaveForm = ['leave_type' => 'annual', 'start_date' => '', 'end_date' => '', 'reason' => ''];

    public function requestLeave(RequestLeave $requestLeave): void
    {
        $this->resetValidation();

        try {
            $leave = $requestLeave->handle(auth()->user(), $this->leaveForm);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['leaveForm.'.$k => $m])->all());

            return;
        }

        $this->leaveForm = ['leave_type' => 'annual', 'start_date' => '', 'end_date' => '', 'reason' => ''];
        $this->toast('Leave requested: '.Display::days((float) $leave->days).'. Your manager has been told.');
    }

    public function cancel(int $leaveId, RequestLeave $requestLeave): void
    {
        $this->attempt(fn () => $requestLeave->cancel(auth()->user(), $leaveId), 'Leave cancelled.');
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();
        $today = $workspace->today()->toDateString();
        $personId = $viewer->personId();
        $user = auth()->user();

        $calendar = $workspace->leaveRequests()
            ->filter(fn ($l) => $l['end_date'] >= $today && in_array($l['status'], ['approved', 'pending'], true))
            ->filter(fn ($l) => $user->can('leave.view_calendar') || $viewer->isSelf($l['person_id']))
            ->sortBy('start_date');

        return view('livewire.people.leave', [
            'calendar' => $calendar,
            'mine' => $workspace->leaveRequests()->where('person_id', $personId)->sortByDesc('start_date'),
            'balance' => $personId ? collect($workspace->data->raw('leave_allowances'))->firstWhere('person_id', $personId) : null,
            'today' => $today,
            'leaveTypes' => $workspace->organisation()['leave_types'],
            'bankHolidays' => collect($workspace->data->raw('bank_holidays'))->filter(fn ($b) => $b['date'] >= $today)->sortBy('date'),
        ]);
    }
}
