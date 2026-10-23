<?php

namespace App\Livewire\People;

use App\Demo\Rows;
use App\Livewire\Concerns\PaginatesCollections;
use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Directory')]
class Directory extends Component
{
    use PaginatesCollections, UsesWorkspace;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $department = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $skill = '';

    #[Url(except: '')]
    public string $location = '';

    #[Url(except: 'cards')]
    public string $layout = 'cards';

    public function mount(): void
    {
        abort_unless($this->viewer()->canSeeDirectory(), 403);
        $this->sort = $this->sort ?: 'name';
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $all = $this->viewer()->people()->where('is_active', true);

        $people = $all
            ->when($this->search !== '', fn ($c) => $c->filter(fn ($p) => str_contains(mb_strtolower($p['first_name'].' '.$p['last_name'].' '.$p['job_title'].' '.$p['email']), mb_strtolower($this->search))))
            ->when($this->department !== '', fn ($c) => $c->where('department_id', (int) $this->department))
            ->when($this->type !== '', fn ($c) => $c->where('employment_type', $this->type))
            ->when($this->skill !== '', fn ($c) => $c->filter(fn ($p) => Rows::of($p['skills'])->contains('skill_id', (int) $this->skill)))
            ->when($this->location !== '', fn ($c) => $c->where('location_city', $this->location));

        $people = $this->applySort($people, [
            'name' => fn ($p) => $p['last_name'].' '.$p['first_name'],
            'title' => fn ($p) => $p['job_title'],
            'department' => fn ($p) => $workspace->departmentName($p['department_id']),
            'location' => fn ($p) => $p['location_city'],
        ]);

        return view('livewire.people.directory', [
            'people' => $this->paginateCollection($people, 24),
            'total' => $all->count(),
            'departments' => $workspace->departments(),
            'skills' => $workspace->skills()->sortBy('name'),
            'locations' => $all->pluck('location_city')->unique()->sort(),
        ]);
    }
}
