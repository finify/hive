<?php

namespace App\Livewire\Events;

use App\Actions\Events\ChangeEventStage;
use App\Actions\Events\RecordSignedContract;
use App\Demo\Rows;
use App\Enums\EventStage;
use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

#[Layout('layouts.app')]
class Show extends Component
{
    use UsesWorkspace;

    public string $code;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public string $targetStage = '';

    public string $stageNote = '';

    /** @var list<string> */
    public array $stageBlockers = [];

    public function mount(string $code): void
    {
        $event = $this->workspace()->eventByCode($code);
        abort_unless($event && $this->viewer()->canSeeEvent($event), 404);
        $this->code = $event['code'];

        if (! in_array($this->tab, $this->tabs($event), true)) {
            $this->tab = 'overview';
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function tabs(array $event): array
    {
        $tabs = ['overview', 'crew'];

        if ($this->viewer()->canSeeBudget($event)) {
            array_push($tabs, 'budget', 'purchase-orders', 'invoices');
        }

        if (auth()->user()->can('timesheets.view_all') || $this->viewer()->isProducerOf($event)) {
            $tabs[] = 'timesheets';
        }

        array_push($tabs, 'files', 'activity');

        return $tabs;
    }

    public function prepareStage(string $stage, ChangeEventStage $change): void
    {
        $event = $this->event();
        $to = EventStage::from($stage);
        Gate::authorize('event.change-stage', [$event, $to]);

        $this->targetStage = $stage;
        $this->stageNote = '';
        $this->stageBlockers = $change->blockers($event['id'], $to);
        $this->dispatch('modal', id: 'stage-modal', show: true);
    }

    public function changeStage(ChangeEventStage $change): void
    {
        $this->validate(['stageNote' => ['nullable', 'string', 'max:500']]);
        $to = EventStage::from($this->targetStage);

        try {
            $change->handle(auth()->user(), $this->event()['id'], $to, $this->stageNote ?: null);
        } catch (ValidationException $e) {
            $this->stageBlockers = collect($e->errors())->flatten()->all();

            return;
        }

        $this->dispatch('modal', id: 'stage-modal', show: false);
        $this->toast("Moved to {$to->label()}.");
    }

    public function recordContract(RecordSignedContract $record): void
    {
        $record->handle(auth()->user(), $this->event()['id']);
        $this->toast('Signed contract added.');
    }

    /**
     * @return array<string, mixed>
     */
    private function event(): array
    {
        $event = $this->workspace()->eventByCode($this->code);
        abort_unless($event !== null, 404);

        return $event;
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();
        $event = $this->event();
        $tabs = $this->tabs($event);

        if (! in_array($this->tab, $tabs, true)) {
            $this->tab = 'overview';
        }

        $stage = EventStage::from($event['stage']);
        $user = auth()->user();
        $nextStages = collect($stage->allowedTransitions())->filter(fn ($to) => $user->can('event.change-stage', [$event, $to]))->values();

        $assignments = $workspace->assignments()->where('event_id', $event['id']);
        $clashKeys = collect($workspace->clashes())
            ->filter(fn ($c) => in_array($event['id'], $c->eventIds(), true))
            ->flatMap(fn ($c) => [$c->first->key(), $c->second->key()])
            ->all();

        $data = [
            'event' => $event,
            'stage' => $stage,
            'tabs' => $tabs,
            'nextStages' => $nextStages,
            'client' => $workspace->clients()->get($event['client_id']),
            'venue' => $workspace->venues()->get($event['venue_id']),
            'financials' => $viewer->canSeeBudget($event) ? $workspace->financials($event['id']) : null,
            'crewCalls' => $workspace->crewCalls()->where('event_id', $event['id'])->sortBy('start_date')->map(fn ($c) => $c + [
                'assignments' => $assignments->where('crew_call_id', $c['id'])->map(fn ($a) => $viewer->presentAssignment($a))->values(),
            ]),
            'clashKeys' => $clashKeys,
            'canManageCrew' => $viewer->canManageCrew($event),
            'canSeeRates' => $user->can('rates.view'),
            'canEdit' => $user->can('events.edit_all') || ($user->can('events.edit') && $viewer->isProducerOf($event)),
        ];

        if ($this->tab === 'budget') {
            $data['lines'] = $workspace->budgetLines($event['id']);
            $data['budget'] = $workspace->budget($event['id']);
            $data['revenueLines'] = collect($workspace->data->raw('revenue_lines'))->where('event_id', $event['id']);
            $data['milestones'] = $workspace->billingMilestones()->where('event_id', $event['id']);
        }

        if ($this->tab === 'purchase-orders') {
            $data['pos'] = $workspace->purchaseOrders()->where('event_id', $event['id']);
        }

        if ($this->tab === 'invoices') {
            $data['invoices'] = $workspace->supplierInvoices()->where('event_id', $event['id']);
        }

        if ($this->tab === 'timesheets') {
            $data['entries'] = $workspace->timesheets()->flatMap(fn ($sheet) => Rows::of($sheet['entries'])
                ->where('event_id', $event['id'])
                ->map(fn ($entry) => ['sheet' => $sheet, 'entry' => $entry]))
                ->sortByDesc(fn ($row) => $row['entry']['date'])
                ->values();
        }

        if ($this->tab === 'activity') {
            $data['activity'] = collect($workspace->seededActivity())->where('ref', $event['code'])
                ->map(fn ($a) => ['at' => $a['at'], 'who' => $workspace->personName($a['causer_id']), 'description' => $a['description'], 'live' => false])
                ->merge(Activity::query()->with('causer')->where('properties->ref', $event['code'])->latest()->limit(50)->get()
                    ->map(fn (Activity $a) => ['at' => $a->created_at, 'who' => $a->causer?->getAttribute('name') ?? 'System', 'description' => $a->description, 'live' => true]))
                ->sortByDesc(fn ($a) => (string) ($a['at'] instanceof \DateTimeInterface ? $a['at']->format('Y-m-d\TH:i:s\Z') : $a['at']))
                ->values();
            $data['history'] = Rows::of($event['stage_history'])->reverse()->values();
        }

        return view('livewire.events.show', $data)->title($event['name']);
    }
}
