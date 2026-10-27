<?php

namespace App\Livewire\People;

use App\Actions\Hr\StartChecklist;
use App\Actions\Hr\TickChecklistItem;
use App\Enums\ChecklistKind;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\Checklist;
use App\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Onboarding and offboarding. HR sees and runs every checklist; line
 * managers, IT and finance see the checklists with items for them.
 */
#[Layout('layouts.app')]
#[Title('Onboarding')]
class Onboarding extends Component
{
    use UsesWorkspace;

    #[Url(except: 'open')]
    public string $show = 'open';

    /** @var array{person_id: string, kind: string} */
    public array $startForm = ['person_id' => '', 'kind' => 'onboarding'];

    public function tick(int $itemId, bool $done, TickChecklistItem $tick): void
    {
        try {
            $tick->handle(auth()->user(), $itemId, $done);
        } catch (AuthorizationException $e) {
            $this->toast($e->getMessage(), 'error');

            return;
        }

        $this->toast($done ? 'Done.' : 'Reopened.');
    }

    public function start(StartChecklist $start): void
    {
        $this->resetValidation();
        $person = Person::query()->find((int) $this->startForm['person_id']);

        if ($person === null) {
            $this->addError('startForm.person_id', 'Choose a person.');

            return;
        }

        try {
            $start->handle(auth()->user(), $person, ChecklistKind::from($this->startForm['kind']));
        } catch (ValidationException $e) {
            $this->addError('startForm.person_id', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->startForm = ['person_id' => '', 'kind' => 'onboarding'];
        $this->toast('Checklist started for '.$person->first_name.'.');
    }

    public function render(): View
    {
        $user = auth()->user();
        $today = $this->workspace()->today()->toDateString();

        $checklists = Checklist::query()->with(['person', 'items'])
            ->when($this->show === 'open', fn ($q) => $q->whereNull('completed_at'), fn ($q) => $q->whereNotNull('completed_at'))
            ->orderBy('anchor_date')
            ->get()
            ->filter(fn (Checklist $c) => $c->person !== null && ($user->can('people.manage') || $c->items->contains(fn ($i) => $i->owner->canTick($user, $c->person))));

        $mine = $checklists->flatMap(fn (Checklist $c) => $c->items->filter(fn ($i) => $i->completed_at === null && $i->owner->canTick($user, $c->person)))->count();

        return view('livewire.people.onboarding', [
            'checklists' => $checklists,
            'mine' => $mine,
            'today' => $today,
            'canStart' => $user->can('people.manage'),
            'people' => $user->can('people.manage') ? Person::query()->where('is_active', true)->orderBy('first_name')->get() : collect(),
        ]);
    }
}
