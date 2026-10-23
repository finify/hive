<?php

namespace App\Livewire;

use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Searches events, people, clients and POs by code or name, scoped to what
 * the user may see.
 */
class GlobalSearch extends Component
{
    use UsesWorkspace;

    public string $query = '';

    public function render(): View
    {
        $results = [];
        $q = mb_strtolower(trim($this->query));

        if (mb_strlen($q) >= 2) {
            $workspace = $this->workspace();
            $viewer = $this->viewer();
            $user = auth()->user();
            $match = fn (string $text) => str_contains(mb_strtolower($text), $q);

            $results['Events'] = $viewer->events()
                ->filter(fn ($e) => $match($e['code'].' '.$e['name'].' '.$e['city']))
                ->take(5)
                ->map(fn ($e) => ['label' => $e['name'], 'meta' => $e['code'], 'href' => route('events.show', $e['code'])])
                ->values()->all();

            if ($viewer->canSeeDirectory()) {
                $results['People'] = $viewer->people()
                    ->filter(fn ($p) => $match($p['first_name'].' '.$p['last_name'].' '.$p['job_title']))
                    ->take(5)
                    ->map(fn ($p) => ['label' => $p['first_name'].' '.$p['last_name'], 'meta' => $p['job_title'], 'href' => route('people.show', $p['id'])])
                    ->values()->all();
            }

            if ($user->can('directory.view')) {
                $results['Clients'] = $workspace->clients()
                    ->filter(fn ($c) => $match($c['name']))
                    ->take(3)
                    ->map(fn ($c) => ['label' => $c['name'], 'meta' => $c['sector'], 'href' => route('events.index', ['client' => $c['id']])])
                    ->values()->all();
            }

            if ($user->can('po.view')) {
                $results['Purchase orders'] = $workspace->purchaseOrders()
                    ->filter(fn ($po) => ($event = $workspace->event($po['event_id'])) && $viewer->canSeeBudget($event) && $match($po['code']))
                    ->take(3)
                    ->map(fn ($po) => ['label' => $po['code'], 'meta' => $workspace->suppliers()->get($po['supplier_id'])['name'], 'href' => route('events.show', [$workspace->event($po['event_id'])['code'], 'tab' => 'purchase-orders'])])
                    ->values()->all();
            }

            $results = array_filter($results);
        }

        return view('livewire.global-search', ['results' => $results]);
    }
}
