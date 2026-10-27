<?php

namespace App\Livewire\Reports;

use App\Reports\Reports;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Reports')]
class Index extends Component
{
    public function render(): View
    {
        return view('livewire.reports.index', ['reports' => Reports::for(auth()->user())]);
    }
}
