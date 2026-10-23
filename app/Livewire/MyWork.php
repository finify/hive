<?php

namespace App\Livewire;

use App\Actions\Resourcing\RespondToBookingRequest;
use App\Actions\Resourcing\SetUnavailability;
use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('My work')]
class MyWork extends Component
{
    use UsesWorkspace;

    /** @var array{start_date: string, end_date: string, note: string} */
    public array $unavailableForm = ['start_date' => '', 'end_date' => '', 'note' => ''];

    public function addUnavailable(SetUnavailability $set): void
    {
        $this->resetValidation();

        try {
            $set->add(auth()->user(), $this->unavailableForm);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['unavailableForm.'.$k => $m])->all());

            return;
        }

        $this->unavailableForm = ['start_date' => '', 'end_date' => '', 'note' => ''];
        $this->toast('Marked unavailable.');
    }

    public function removeUnavailable(int $id, SetUnavailability $set): void
    {
        $this->attempt(fn () => $set->remove(auth()->user(), $id), 'Removed.');
    }

    public function respond(int $assignmentId, bool $accept, RespondToBookingRequest $respond): void
    {
        $status = null;
        $ok = $this->attempt(function () use ($respond, $assignmentId, $accept, &$status) {
            $status = $respond->handle(auth()->user(), $assignmentId, $accept);
        }, $accept ? 'Booking accepted.' : 'Booking declined. The producer has been told.');

        if ($ok && $accept && $status?->value === 'confirmed') {
            $this->toast('You’re confirmed. The call sheet follows nearer the time.');
        }
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $personId = auth()->user()->person_id;
        $today = $workspace->today()->toDateString();

        $mine = $workspace->assignments()
            ->where('person_id', $personId)
            ->map(fn ($a) => ['assignment' => $a, 'event' => $workspace->event($a['event_id']), 'call' => $workspace->crewCalls()->get($a['crew_call_id'])]);

        return view('livewire.my-work', [
            'requests' => $this->insights()->myBookingRequests()->map(fn ($a) => ['assignment' => $a, 'event' => $workspace->event($a['event_id']), 'call' => $workspace->crewCalls()->get($a['crew_call_id'])]),
            'upcoming' => $mine->filter(fn ($r) => $r['assignment']['end_date'] >= $today && in_array($r['assignment']['status'], ['confirmed', 'first-hold', 'second-hold'], true) && $r['assignment']['responded_at'] !== null)->sortBy(fn ($r) => $r['assignment']['start_date']),
            'released' => $mine->filter(fn ($r) => in_array($r['assignment']['status'], ['released', 'declined'], true) && $r['assignment']['end_date'] >= $today),
            'documents' => $workspace->documents()->where('person_id', $personId),
            'unavailable' => $workspace->unavailability()->where('person_id', $personId)->filter(fn ($u) => $u['end_date'] >= $today),
            'isFreelancer' => $workspace->isFreelancer($personId),
            'clashes' => collect($this->insights()->clashes())->filter(fn ($c) => $c->personId === $personId),
        ]);
    }
}
