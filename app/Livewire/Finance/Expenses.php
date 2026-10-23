<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Expenses')]
class Expenses extends Component
{
    use UsesWorkspace;

    public function render(): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();
        $user = auth()->user();

        $expenses = $workspace->expenses()
            ->filter(function ($x) use ($user, $viewer, $workspace) {
                if ($viewer->isSelf($x['person_id']) || $user->can('expenses.view_all')) {
                    return true;
                }

                $event = $workspace->event($x['event_id']);

                return $user->can('expenses.approve') && $event && $viewer->isProducerOf($event);
            })
            ->sortByDesc('date');

        return view('livewire.finance.expenses', ['expenses' => $expenses]);
    }
}
