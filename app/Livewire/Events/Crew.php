<?php

namespace App\Livewire\Events;

use App\Actions\Resourcing\BookPerson;
use App\Actions\Resourcing\CallSheet;
use App\Actions\Resourcing\ConfirmHolds;
use App\Actions\Resourcing\ReleaseAssignment;
use App\Actions\Resourcing\SaveCrewCall;
use App\Data\Clash;
use App\Enums\AssignmentStatus;
use App\Enums\EventStage;
use App\Enums\PhaseType;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\CrewCall;
use App\Models\Event;
use App\Models\Person;
use App\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The event's crew tab: crew calls, who is booked, booking people with the
 * clash and working time checks, confirming holds, and call sheets.
 */
class Crew extends Component
{
    use UsesWorkspace;

    public int $eventId;

    /** Crew call being edited; null while adding. */
    public ?int $editingCallId = null;

    /** @var array<string, mixed> */
    public array $callForm = [];

    public ?int $bookingCallId = null;

    /** @var array{person_id: string, start_date: string, end_date: string, status: string, clash_override_reason: string} */
    public array $booking = ['person_id' => '', 'start_date' => '', 'end_date' => '', 'status' => 'first-hold', 'clash_override_reason' => ''];

    public string $sheetDate = '';

    public function mount(int $eventId, ?int $bookCall = null, ?int $bookPerson = null): void
    {
        $this->eventId = $eventId;
        $this->sheetDate = $this->defaultSheetDate();

        if ($bookCall !== null && $this->canManage()) {
            $this->startBooking($bookCall, $bookPerson);
        }
    }

    private function canManage(): bool
    {
        return auth()->user()->can('event.manage-crew', [$this->workspace()->event($this->eventId) ?? []]);
    }

    private function model(): Event
    {
        return Event::query()->findOrFail($this->eventId);
    }

    // ---------------------------------------------------------------- crew calls

    public function startCall(?int $callId = null): void
    {
        $this->resetValidation();
        $this->editingCallId = $callId;
        $event = $this->workspace()->event($this->eventId);

        if ($callId !== null) {
            $call = CrewCall::query()->where('event_id', $this->eventId)->findOrFail($callId);
            $this->callForm = [
                'role_name' => $call->role_name, 'quantity' => $call->quantity, 'skill_id' => $call->skill_id ?? '', 'min_skill_level' => $call->min_skill_level ?? '',
                'start_date' => $call->start_date->toDateString(), 'end_date' => $call->end_date->toDateString(), 'phase' => $call->phase ?? '',
                'call_time' => $call->call_time, 'wrap_time' => $call->wrap_time, 'location' => $call->location ?? '',
                'budgeted_rate' => $call->budgeted_rate_minor ? number_format($call->budgeted_rate_minor / 100, 2, '.', '') : '', 'notes' => $call->notes ?? '',
            ];
        } else {
            $this->callForm = [
                'role_name' => '', 'quantity' => 1, 'skill_id' => '', 'min_skill_level' => '', 'start_date' => $event['start_date'] ?? '',
                'end_date' => $event['end_date'] ?? '', 'phase' => 'live', 'call_time' => '08:00', 'wrap_time' => '18:00',
                'location' => '', 'budgeted_rate' => '', 'notes' => '',
            ];
        }

        $this->dispatch('modal', id: 'call-modal', show: true);
    }

    public function saveCall(SaveCrewCall $save): void
    {
        $this->resetValidation();
        $existing = $this->editingCallId ? CrewCall::query()->where('event_id', $this->eventId)->findOrFail($this->editingCallId) : null;

        try {
            $save->handle(auth()->user(), $this->model(), array_map(fn ($v) => $v === '' ? null : $v, $this->callForm), $existing);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['callForm.'.$k => $m])->all());

            return;
        }

        $this->dispatch('modal', id: 'call-modal', show: false);
        $this->toast($existing ? 'Crew call updated.' : 'Crew call added.');
    }

    public function deleteCall(int $callId, SaveCrewCall $save): void
    {
        $call = CrewCall::query()->where('event_id', $this->eventId)->findOrFail($callId);
        $this->attempt(fn () => $save->delete(auth()->user(), $call), 'Crew call removed.');
    }

    // ---------------------------------------------------------------- booking

    public function startBooking(int $callId, ?int $personId = null): void
    {
        $this->resetValidation();
        $call = CrewCall::query()->where('event_id', $this->eventId)->findOrFail($callId);
        $this->bookingCallId = $call->id;
        $this->booking = [
            'person_id' => $personId ? (string) $personId : '',
            'start_date' => $call->start_date->toDateString(),
            'end_date' => $call->end_date->toDateString(),
            'status' => 'first-hold',
            'clash_override_reason' => '',
        ];
        $this->dispatch('modal', id: 'book-modal', show: true);
    }

    public function book(BookPerson $book): void
    {
        $this->resetValidation();
        $call = CrewCall::query()->where('event_id', $this->eventId)->findOrFail($this->bookingCallId);

        try {
            $assignment = $book->handle(auth()->user(), $call, $this->booking);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['booking.'.$k => $m])->all());

            return;
        }

        $this->dispatch('modal', id: 'book-modal', show: false);
        $name = $this->workspace()->personName($assignment->person_id);
        $this->toast($assignment->responded_at === null ? "Booking request sent to {$name}." : "{$name} booked.");
    }

    public function release(int $assignmentId, ReleaseAssignment $release): void
    {
        $this->attempt(fn () => $release->handle(auth()->user(), $assignmentId), 'Released. They have been told.');
    }

    public function confirm(int $assignmentId, ConfirmHolds $confirm): void
    {
        $this->report($confirm->handle(auth()->user(), [$assignmentId]));
    }

    public function confirmFirstHolds(ConfirmHolds $confirm): void
    {
        $this->report($confirm->handle(auth()->user(), $confirm->firstHoldsOn($this->eventId)));
    }

    /**
     * @param  array{confirmed: int, skipped: array<string, list<string>>}  $result
     */
    private function report(array $result): void
    {
        if ($result['confirmed'] > 0) {
            $this->toast($result['confirmed'] === 1 ? 'Confirmed. They have been told.' : "Confirmed {$result['confirmed']} people. They have been told.");
        }

        foreach ($result['skipped'] as $name => $reasons) {
            $this->toast("{$name} stays a hold: ".implode(' ', $reasons), 'error');
        }
    }

    #[On('clash-resolved')]
    public function refresh(): void
    {
        $this->workspace()->forget();
    }

    // ---------------------------------------------------------------- call sheets

    public function sendCallSheet(CallSheet $sheets): void
    {
        $count = 0;
        $ok = $this->attempt(function () use ($sheets, &$count) {
            $count = $sheets->send(auth()->user(), $this->eventId, $this->sheetDate);
        }, 'Call sheet emailed.');

        if ($ok) {
            $this->toast("Sent to {$count} ".($count === 1 ? 'person' : 'people').'.');
        }
    }

    private function defaultSheetDate(): string
    {
        $days = app(CallSheet::class)->days($this->eventId);
        $today = $this->workspace()->today()->toDateString();

        return collect($days)->first(fn ($d) => $d >= $today) ?? (end($days) ?: '');
    }

    public function render(BookPerson $book, CallSheet $sheets): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();
        $event = $workspace->event($this->eventId) ?? [];
        $canManage = $viewer->canManageCrew($event);
        $assignments = $workspace->assignments()->where('event_id', $this->eventId);

        $clashByBooking = [];

        foreach ($workspace->clashes() as $clash) {
            /** @var Clash $clash */
            if (in_array($this->eventId, $clash->eventIds(), true)) {
                $clashByBooking[$clash->first->key()] = $clash->key();
                $clashByBooking[$clash->second->key()] = $clash->key();
            }
        }

        $warnings = [];

        foreach ($assignments->pluck('person_id')->unique() as $personId) {
            $warnings += $workspace->shiftWarnings($personId);
        }

        $crewCalls = $workspace->crewCalls()->where('event_id', $this->eventId)->sortBy('start_date')->map(fn ($c) => $c + [
            'assignments' => $assignments->where('crew_call_id', $c['id'])->sortBy('start_date')->map(fn ($a) => $viewer->presentAssignment($a))->values(),
            'filled' => $assignments->where('crew_call_id', $c['id'])->filter(fn ($a) => in_array($a['status'], ['confirmed', 'first-hold'], true))->count(),
        ]);

        $data = [
            'event' => $event,
            'crewCalls' => $crewCalls,
            'clashByBooking' => $clashByBooking,
            'warnings' => $warnings,
            'canManage' => $canManage,
            'canSeeRates' => auth()->user()->can('rates.view'),
            'firstHolds' => $canManage && EventStage::from($event['stage'])->isConfirmedOrLater() && ! EventStage::from($event['stage'])->isTerminal()
                ? $assignments->where('status', AssignmentStatus::FirstHold->value)->count() : 0,
            'sheetDays' => $sheets->days($this->eventId),
            'canSeeSheets' => $canManage,
            'skills' => $workspace->skills()->sortBy('name'),
            'phaseTypes' => collect(PhaseType::cases())->mapWithKeys(fn (PhaseType $t) => [$t->value => $t->label()]),
        ];

        if ($this->bookingCallId !== null && ($call = CrewCall::query()->where('event_id', $this->eventId)->find($this->bookingCallId))) {
            $dates = $this->validDates() ? DateRange::of($this->booking['start_date'], $this->booking['end_date']) : DateRange::of($call->start_date->toDateString(), $call->end_date->toDateString());
            $data['bookingCall'] = $call;
            $data['suggestions'] = $workspace->availablePeople($call->role_name, $dates, $call->skill_id, (int) $call->min_skill_level, preferCountry: $event['country'] ?? null)->take(6);
            $data['everyone'] = Person::query()->active()->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'preferred_name', 'job_title']);
            $data['preview'] = $this->booking['person_id'] !== '' && $this->validDates()
                ? $book->preview($call, (int) $this->booking['person_id'], $this->booking['start_date'], $this->booking['end_date'], AssignmentStatus::from($this->booking['status']))
                : null;
            $data['bookingIsFreelancer'] = $this->booking['person_id'] !== '' && $workspace->isFreelancer((int) $this->booking['person_id']);
        }

        return view('livewire.events.crew', $data);
    }

    private function validDates(): bool
    {
        $s = $this->booking['start_date'];
        $e = $this->booking['end_date'];

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $e) === 1 && $s <= $e;
    }
}
