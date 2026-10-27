<?php

namespace App\Livewire\People;

use App\Actions\Gdpr\AnonymisePerson;
use App\Actions\People\AddDayRate;
use App\Actions\People\UploadDocument;
use App\Enums\DocumentType;
use App\Http\Controllers\DocumentDownloadController;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\Document;
use App\Models\Person;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class Show extends Component
{
    use UsesWorkspace, WithFileUploads;

    public int $person;

    /** @var array<string, string> */
    public array $newRate = ['role_name' => '', 'amount' => '', 'effective_from' => ''];

    /** @var array<string, string> */
    public array $newDocument = ['type' => 'public-liability-insurance', 'title' => '', 'issued_at' => '', 'expires_at' => ''];

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public function mount(int $person): void
    {
        abort_unless($this->workspace()->person($person) && $this->viewer()->canSeePerson($person), 404);
        $this->person = $person;
        $this->newRate['role_name'] = (string) ($this->workspace()->person($person)['job_title'] ?? '');
        $this->newRate['effective_from'] = $this->workspace()->today()->addDay()->toDateString();
    }

    public string $erasureReason = '';

    public function anonymise(AnonymisePerson $anonymise): void
    {
        $this->resetValidation();

        try {
            $anonymise->handle(auth()->user(), $this->person, $this->erasureReason);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->dispatch('modal', id: 'erase-modal', show: false);
        $this->toast('Anonymised. Their financial records stay, under “Former staff member '.$this->person.'”.');
    }

    private function model(): Person
    {
        return Person::query()->findOrFail($this->person);
    }

    public function addRate(AddDayRate $add): void
    {
        $this->resetValidation();

        try {
            $add->handle(auth()->user(), $this->model(), $this->newRate);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['newRate.'.$k => $m])->all());

            return;
        }

        $this->workspace()->forget();
        $this->newRate['amount'] = '';
        $this->toast('New day rate saved. Costs already posted keep their old rate.');
    }

    public function uploadDocument(UploadDocument $uploadDocument): void
    {
        $this->resetValidation();

        try {
            $uploadDocument->handle(auth()->user(), $this->model(), $this->newDocument + ['file' => $this->upload]);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => [($k === 'file' ? 'upload' : 'newDocument.'.$k) => $m])->all());

            return;
        }

        $this->workspace()->forget();
        $this->reset('upload');
        $this->newDocument = ['type' => 'public-liability-insurance', 'title' => '', 'issued_at' => '', 'expires_at' => ''];
        $this->toast('Document uploaded.');
    }

    /**
     * @return list<string>
     */
    private function tabs(): array
    {
        $viewer = $this->viewer();
        $self = $viewer->isSelf($this->person);
        $user = auth()->user();
        $tabs = ['overview', 'bookings'];

        if ($self || $user->can('timesheets.view_all')) {
            $tabs[] = 'timesheets';
        }

        if ($self || $user->can('leave.view_calendar')) {
            $tabs[] = 'leave';
        }

        if ($self || $user->can('documents.view') || $user->can('documents.view_status')) {
            $tabs[] = 'documents';
        }

        $tabs[] = 'skills';

        if ($viewer->canSeeRatesOf($this->person)) {
            $tabs[] = 'rates';
        }

        if ($viewer->canSeeRestrictedOf($this->person)) {
            $tabs[] = 'restricted';
        }

        return $tabs;
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();
        $tabs = $this->tabs();

        if (! in_array($this->tab, $tabs, true)) {
            $this->tab = 'overview';
        }

        $person = $viewer->presentPerson($workspace->personWithSensitive($this->person) ?? abort(404));
        $today = $workspace->today()->toDateString();

        $bookings = $workspace->assignments()
            ->where('person_id', $this->person)
            ->map(fn ($a) => ['assignment' => $viewer->presentAssignment($a), 'event' => $workspace->event($a['event_id'])])
            ->filter(fn ($row) => $row['event'] && ($viewer->canSeeEvent($row['event']) || $viewer->can('rota.view_all')))
            ->sortByDesc(fn ($row) => $row['assignment']['start_date']);

        return view('livewire.people.show', [
            'p' => $person,
            'tabs' => $tabs,
            'manager' => $workspace->person($person['line_manager_id'] ?? null),
            'upcoming' => $bookings->filter(fn ($r) => $r['assignment']['end_date'] >= $today)->sortBy(fn ($r) => $r['assignment']['start_date']),
            'past' => $bookings->filter(fn ($r) => $r['assignment']['end_date'] < $today),
            'sheets' => in_array('timesheets', $tabs, true) ? $workspace->timesheets()->where('person_id', $this->person)->sortByDesc('week_start') : collect(),
            'leave' => $workspace->leaveRequests()->where('person_id', $this->person)->sortByDesc('start_date'),
            'documents' => $workspace->documents()->where('person_id', $this->person),
            'canSeeDocumentDetail' => $viewer->isSelf($this->person) || auth()->user()->can('documents.view'),
            'canEdit' => auth()->user()->can('update', $model = $this->model()),
            'canAddRate' => auth()->user()->can('addRate', $model),
            'canUpload' => auth()->user()->can('uploadDocument', $model),
            'downloads' => Document::query()->whereMorphedTo('documentable', $model)->whereNotNull('file_path')->get()
                ->filter(fn (Document $d) => auth()->user()->can('download', $d))
                ->mapWithKeys(fn (Document $d) => [$d->id => DocumentDownloadController::link($d)]),
            'documentTypes' => DocumentType::options(),
            'expired' => $workspace->expiredMandatoryDocuments($this->person),
        ])->title(($person['preferred_name'] ?: $person['first_name']).' '.$person['last_name']);
    }
}
