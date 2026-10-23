<?php

namespace App\Livewire\Directory;

use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Venues')]
class Venues extends Component
{
    use UsesWorkspace;

    #[Url(except: '')]
    public string $search = '';

    public function render(): View
    {
        $items = $this->workspace()->venues()
            ->when($this->search !== '', fn ($c) => $c->filter(fn ($i) => str_contains(mb_strtolower($i['name']), mb_strtolower($this->search))))
            ->sortBy('name');

        return view('livewire.directory.venues', ['items' => $items]);
    }
}
