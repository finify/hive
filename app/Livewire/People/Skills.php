<?php

namespace App\Livewire\People;

use App\Demo\Rows;
use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The skills catalogue: who has each skill, at what level (1 to 5), and
 * whether it has been verified. Levels are changed on a person's profile.
 */
#[Layout('layouts.app')]
#[Title('Skills')]
class Skills extends Component
{
    use UsesWorkspace;

    #[Url(except: '')]
    public string $search = '';

    public function render(): View
    {
        $people = $this->viewer()->people()->filter(fn ($p) => $p['is_active']);
        $q = mb_strtolower(trim($this->search));

        $skills = $this->workspace()->skills()
            ->map(function ($skill) use ($people) {
                $holders = $people->map(function ($p) use ($skill) {
                    $s = Rows::of($p['skills'] ?? [])->firstWhere('skill_id', $skill['id']);

                    return $s ? ['id' => $p['id'], 'name' => $p['first_name'].' '.$p['last_name'], 'level' => (int) $s['level'], 'verified' => (bool) $s['verified']] : null;
                })->filter()->sortByDesc('level')->values();

                return $skill + ['holders' => $holders];
            })
            ->filter(fn ($s) => $q === '' || str_contains(mb_strtolower($s['name'].' '.$s['category']), $q)
                || $s['holders']->contains(fn ($h) => str_contains(mb_strtolower($h['name']), $q)))
            ->groupBy('category');

        return view('livewire.people.skills', ['groups' => $skills, 'total' => $this->workspace()->skills()->count()]);
    }
}
