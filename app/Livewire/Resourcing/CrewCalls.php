<?php

namespace App\Livewire\Resourcing;

use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Crew calls')]
class CrewCalls extends Component
{
    use UsesWorkspace;

    #[Url(except: 'open')]
    public string $show = 'open';

    public function render(): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();
        $today = $workspace->today()->toDateString();
        $events = $viewer->events()->reject(fn ($e) => in_array($e['stage'], ['cancelled', 'lost'], true));

        $calls = $workspace->crewCalls()
            ->filter(fn ($c) => $events->has($c['event_id']) && $c['end_date'] >= $today)
            ->map(function ($c) use ($workspace) {
                $filled = $workspace->assignments()->where('crew_call_id', $c['id'])->filter(fn ($a) => in_array($a['status'], ['confirmed', 'first-hold'], true))->count();

                return $c + ['filled' => $filled, 'event' => $workspace->event($c['event_id'])];
            })
            ->when($this->show === 'open', fn ($c) => $c->filter(fn ($call) => $call['filled'] < $call['quantity']))
            ->sortBy('start_date')
            ->values();

        return view('livewire.resourcing.crew-calls', ['calls' => $calls]);
    }
}
