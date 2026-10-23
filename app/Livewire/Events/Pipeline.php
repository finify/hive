<?php

namespace App\Livewire\Events;

use App\Actions\Events\ChangeEventStage;
use App\Enums\EventStage;
use App\Livewire\Concerns\UsesWorkspace;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Pipeline board')]
class Pipeline extends Component
{
    use UsesWorkspace;

    /** @var list<string> */
    public array $blockers = [];

    public string $blockedEvent = '';

    /**
     * Called when a card is dropped on another column. The stage rules apply
     * exactly as they do on the event page.
     */
    public function move(string $code, string $stage, ChangeEventStage $change): void
    {
        $event = $this->workspace()->eventByCode($code);
        $to = EventStage::tryFrom($stage);

        if (! $event || ! $to || $event['stage'] === $stage) {
            return;
        }

        if (Gate::denies('event.change-stage', [$event, $to])) {
            $this->toast("Only {$this->workspace()->personName($event['producer_id'])} or the operations director can move {$event['code']}.", 'error');

            return;
        }

        try {
            $change->handle(auth()->user(), $event['id'], $to);
        } catch (ValidationException $e) {
            $this->blockedEvent = $event['name'].' can’t move to '.$to->label().' yet';
            $this->blockers = collect($e->errors())->flatten()->all();
            $this->dispatch('modal', id: 'blocked-modal', show: true);

            return;
        }

        $this->toast("{$event['code']} moved to {$to->label()}.");
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $events = $this->viewer()->events();
        $columns = [];

        foreach (EventStage::pipeline() as $stage) {
            $inStage = $events->where('stage', $stage->value)->sortBy('start_date');
            $columns[] = [
                'stage' => $stage,
                'events' => $inStage,
                'value' => Money::sum($inStage->map(fn ($e) => Money::of((int) ($e['client_fee_minor'] ?? 0)))),
            ];
        }

        $lost = $events->where('stage', EventStage::Lost->value);

        return view('livewire.events.pipeline', [
            'columns' => $columns,
            'workspace' => $workspace,
            'lost' => $lost,
            'winRate' => $this->winRate($events),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     */
    private function winRate($events): ?int
    {
        $won = $events->filter(fn ($e) => EventStage::from($e['stage'])->isConfirmedOrLater() || $e['stage'] === EventStage::Cancelled->value)->count();
        $lost = $events->where('stage', EventStage::Lost->value)->count();

        return ($won + $lost) ? (int) round($won * 100 / ($won + $lost)) : null;
    }
}
