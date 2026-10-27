<?php

namespace App\Livewire\Help;

use App\Support\Help;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public string $key;

    public function mount(string $key): void
    {
        abort_unless(array_key_exists($key, Help::for(auth()->user())), 404);
        $this->key = $key;
    }

    public function render(): View
    {
        $all = Help::for(auth()->user());
        $guide = $all[$this->key];

        // A button to the page the guide is about, when there is one to go to.
        $open = collect($guide['routes'])->first(fn ($r) => ! str_contains($r, '*') && Route::has($r) && ! Route::getRoutes()->getByName($r)?->parameterNames());

        return view('livewire.help.show', [
            'guide' => $guide,
            'body' => Help::body($this->key),
            'open' => $open,
            'related' => collect($all)->filter(fn ($g, $k) => $g['group'] === $guide['group'] && $k !== $this->key),
        ])->title($guide['title'].' · Help');
    }
}
