<?php

namespace App\Livewire\Events;

use App\Enums\EventStage;
use App\Livewire\Concerns\PaginatesCollections;
use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Events')]
class Index extends Component
{
    use PaginatesCollections, UsesWorkspace;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $stage = '';

    #[Url(except: '')]
    public string $client = '';

    #[Url(except: '')]
    public string $producer = '';

    #[Url(except: '')]
    public string $country = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        $this->sort = $this->sort ?: 'start';
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'stage', 'client', 'producer', 'country', 'from', 'to');
        $this->resetPage();
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();
        $all = $viewer->events();

        $events = $all
            ->when($this->search !== '', fn ($c) => $c->filter(fn ($e) => str_contains(mb_strtolower($e['name'].' '.$e['code'].' '.$e['city']), mb_strtolower($this->search))))
            ->when($this->stage === 'active', fn ($c) => $c->whereIn('stage', ['confirmed', 'pre-production', 'live', 'wrap']))
            ->when($this->stage !== '' && $this->stage !== 'active', fn ($c) => $c->where('stage', $this->stage))
            ->when($this->client !== '', fn ($c) => $c->where('client_id', (int) $this->client))
            ->when($this->producer === 'me', fn ($c) => $c->where('producer_id', $viewer->personId()))
            ->when($this->producer !== '' && $this->producer !== 'me', fn ($c) => $c->where('producer_id', (int) $this->producer))
            ->when($this->country !== '', fn ($c) => $c->where('country', $this->country))
            ->when($this->from !== '', fn ($c) => $c->filter(fn ($e) => $e['end_date'] >= $this->from))
            ->when($this->to !== '', fn ($c) => $c->filter(fn ($e) => $e['start_date'] <= $this->to));

        $events = $this->applySort($events, [
            'code' => fn ($e) => $e['code'],
            'name' => fn ($e) => $e['name'],
            'client' => fn ($e) => $workspace->clients()->get($e['client_id'])['name'] ?? '',
            'start' => fn ($e) => $e['start_date'],
            'stage' => fn ($e) => EventStage::from($e['stage'])->position(),
            'fee' => fn ($e) => $e['client_fee_minor'] ?? 0,
        ]);

        return view('livewire.events.index', [
            'events' => $this->paginateCollection($events),
            'total' => $all->count(),
            'stages' => EventStage::cases(),
            'clients' => $workspace->clients()->sortBy('name'),
            'producers' => $all->pluck('producer_id')->unique()->mapWithKeys(fn ($id) => [$id => $workspace->personName($id)])->sort(),
            'countries' => $all->pluck('country')->unique()->sort(),
            'filtered' => $this->search !== '' || $this->stage !== '' || $this->client !== '' || $this->producer !== '' || $this->country !== '' || $this->from !== '' || $this->to !== '',
        ]);
    }
}
