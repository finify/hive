<?php

namespace App\Livewire\Resourcing;

use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Clashes')]
class Clashes extends Component
{
    use UsesWorkspace;

    #[On('clash-resolved')]
    public function refreshList(): void
    {
        $this->workspace()->forget();
    }

    public function render(): View
    {
        return view('livewire.resourcing.clashes', [
            'clashes' => $this->insights()->clashes(),
        ]);
    }
}
