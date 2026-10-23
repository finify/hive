<?php

namespace App\Livewire;

use App\Support\Navigation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Planned extends Component
{
    public string $module;

    public function mount(string $module): void
    {
        abort_unless(array_key_exists($module, Navigation::planned()), 404);
        $this->module = $module;
    }

    public function render(): View
    {
        $info = Navigation::planned()[$this->module];

        return view('livewire.planned', ['info' => $info])->title($info['title']);
    }
}
