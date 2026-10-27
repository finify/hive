<?php

namespace App\Livewire\Help;

use App\Support\Help;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The staff guide: every part of Hive the user can use, grouped as the
 * sidebar is, with a search across the guides' text.
 */
#[Layout('layouts.app')]
#[Title('Help')]
class Index extends Component
{
    #[Url(except: '')]
    public string $search = '';

    public function render(): View
    {
        $q = mb_strtolower(trim($this->search));
        $guides = collect(Help::for(auth()->user()))
            ->filter(fn ($g, $key) => $q === '' || str_contains(mb_strtolower($g['title'].' '.$g['summary']), $q) || str_contains(Help::text($key), $q))
            ->map(fn ($g, $key) => $g + ['key' => $key])
            ->groupBy('group');

        return view('livewire.help.index', ['groups' => $guides]);
    }
}
