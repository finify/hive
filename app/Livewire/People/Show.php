<?php

namespace App\Livewire\People;

use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    use UsesWorkspace;

    public int $person;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public function mount(int $person): void
    {
        abort_unless($this->workspace()->person($person) && $this->viewer()->canSeePerson($person), 404);
        $this->person = $person;
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

        $person = $viewer->presentPerson($workspace->person($this->person));
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
            'expired' => $workspace->expiredMandatoryDocuments($this->person),
        ])->title(($person['preferred_name'] ?: $person['first_name']).' '.$person['last_name']);
    }
}
