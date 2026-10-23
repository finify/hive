<?php

namespace App\Livewire\People;

use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Leave')]
class Leave extends Component
{
    use UsesWorkspace;

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
            'bankHolidays' => collect($workspace->data->raw('bank_holidays'))->filter(fn ($b) => $b['date'] >= $today)->sortBy('date'),
        ]);
    }
}
