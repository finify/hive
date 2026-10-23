<?php

namespace App\Livewire\Events;

use App\Actions\Events\ApplyTaskTemplate;
use App\Actions\Events\ChangeEventStage;
use App\Actions\Events\SaveTask;
use App\Actions\Events\UploadEventFile;
use App\Demo\Rows;
use App\Enums\DocumentType;
use App\Enums\EventStage;
use App\Http\Controllers\DocumentDownloadController;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\Document;
use App\Models\Event;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Spatie\Activitylog\Models\Activity;

#[Layout('layouts.app')]
class Show extends Component
{
    use UsesWorkspace, WithFileUploads;

    public string $code;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public string $targetStage = '';

    public string $stageNote = '';

    /** @var list<string> */
    public array $stageBlockers = [];

    /** @var array{title: string, assignee_id: string, due_date: string} */
    public array $newTask = ['title' => '', 'assignee_id' => '', 'due_date' => ''];

    public string $fileType = 'brief';

    /** From "Book" on the availability search: open the booking form for this call and person. */
    #[Url(as: 'call', except: null)]
    public ?int $bookCall = null;

    #[Url(as: 'person', except: null)]
    public ?int $bookPerson = null;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

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
        $tabs = ['overview', 'tasks', 'crew'];

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

    public function addTask(SaveTask $save): void
    {
        $this->resetValidation();

        try {
            $save->add(auth()->user(), $this->model(), $this->newTask);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['newTask.'.$k => $m])->all());

            return;
        }

        $this->newTask = ['title' => '', 'assignee_id' => $this->newTask['assignee_id'], 'due_date' => ''];
        $this->toast('Task added.');
    }

    public function cycleTask(int $taskId, SaveTask $save): void
    {
        $task = Task::query()->where('event_id', $this->event()['id'])->findOrFail($taskId);
        $save->setStatus(auth()->user(), $task, $task->status->next());
    }

    public function applyTemplate(ApplyTaskTemplate $apply): void
    {
        $this->resetValidation();

        try {
            $count = $apply->handle(auth()->user(), $this->model());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return;
        }

        $this->toast("Added {$count} tasks from the standard list.");
    }

    /**
     * Opens the files tab with the contract chosen, from the overview.
     */
    public function startContractUpload(): void
    {
        $this->fileType = DocumentType::Contract->value;
        $this->tab = 'files';
    }

    public function uploadFile(UploadEventFile $uploadFile): void
    {
        $this->resetValidation();

        try {
            $uploadFile->handle(auth()->user(), $this->model(), ['type' => $this->fileType, 'file' => $this->upload]);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => [($k === 'file' ? 'upload' : 'fileType') => $m])->all());

            return;
        }

        $this->reset('upload');
        $this->toast($this->fileType === DocumentType::Contract->value ? 'Signed contract uploaded. The event can now be confirmed.' : 'File uploaded.');
        $this->fileType = 'brief';
    }

    private function model(): Event
    {
        return Event::query()->findOrFail($this->event()['id']);
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
            'clashKeys' => $clashKeys,
            'bookCall' => $this->bookCall,
            'bookPerson' => $this->bookPerson,
            'canEdit' => $user->can('event.edit', [$event]),
        ];

        if ($this->tab === 'tasks') {
            $data['tasks'] = Rows::of($event['tasks'])->map(fn ($t) => $t + ['can_update' => $user->can('event.update-task', [$event, $t['assignee_id']])]);
            $data['assignees'] = collect([$event['producer_id'], ...array_column($event['team'], 'person_id')])
                ->filter()->unique()->mapWithKeys(fn ($id) => [$id => $workspace->personName($id)]);
        }

        if ($this->tab === 'files') {
            $data['downloads'] = Document::query()->whereMorphedTo('documentable', $this->model())->whereNotNull('file_path')->get()
                ->filter(fn (Document $d) => $user->can('download', $d))
                ->mapWithKeys(fn (Document $d) => [$d->id => DocumentDownloadController::link($d)]);
            $data['fileTypes'] = DocumentType::eventOptions();
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
