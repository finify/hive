<?php

namespace App\Livewire\Time;

use App\Actions\Time\AddTimesheetEntry;
use App\Actions\Time\SubmitTimesheet;
use App\Demo\Rows;
use App\Livewire\Concerns\UsesWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('My timesheets')]
class Timesheets extends Component
{
    use UsesWorkspace;

    #[Url(except: '')]
    public string $week = '';

    public string $date = '';

    public string $work = '';

    public string $start_time = '08:00';

    public string $end_time = '18:00';

    public int $break_minutes = 60;

    public string $notes = '';

    public function mount(): void
    {
        $today = $this->workspace()->today();
        $this->week = $this->week ?: $today->startOfWeek()->toDateString();
        $this->date = $today->toDateString();
    }

    public function shiftWeek(int $by): void
    {
        $this->week = CarbonImmutable::parse($this->week)->addWeeks($by)->toDateString();
        $this->date = $this->week;
    }

    public function addEntry(AddTimesheetEntry $add): void
    {
        $this->resetValidation();
        try {
            $add->handle(auth()->user(), [
                'date' => $this->date, 'work' => $this->work, 'start_time' => $this->start_time,
                'end_time' => $this->end_time, 'break_minutes' => $this->break_minutes, 'notes' => $this->notes ?: null,
            ]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->resetValidation();
        $this->notes = '';
        $this->week = CarbonImmutable::parse($this->date)->startOfWeek()->toDateString();
        $this->date = CarbonImmutable::parse($this->date)->addDay()->toDateString();
        $this->toast('Entry added.');
    }

    public function submit(int $sheetId, SubmitTimesheet $submit): void
    {
        $this->attempt(fn () => $submit->handle(auth()->user(), $sheetId), 'Timesheet submitted. Each producer approves their part.');
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $personId = auth()->user()->person_id;
        $sheets = $workspace->timesheets()->where('person_id', $personId);
        $sheet = $sheets->firstWhere('week_start', $this->week);
        $weekStart = CarbonImmutable::parse($this->week);

        $bookedEvents = $workspace->assignments()
            ->where('person_id', $personId)
            ->filter(fn ($a) => in_array($a['status'], ['confirmed', 'first-hold'], true))
            ->pluck('event_id')
            ->merge($workspace->events()->filter(fn ($e) => Rows::of($e['team'])->contains('person_id', $personId))->keys())
            ->unique()
            ->map(fn ($id) => $workspace->event($id))
            ->filter(fn ($e) => $e && ! in_array($e['stage'], ['cancelled', 'lost', 'closed'], true))
            ->sortBy('start_date');

        return view('livewire.time.timesheets', [
            'sheet' => $sheet,
            'flags' => $sheet ? $workspace->entryFlags($sheet) : [],
            'weekStart' => $weekStart,
            'history' => $sheets->sortByDesc('week_start')->take(8),
            'bookedEvents' => $bookedEvents,
            'codes' => AddTimesheetEntry::INTERNAL_CODES,
            'editable' => ! $sheet || in_array($sheet['status'], ['draft', 'returned'], true),
            'locked' => $workspace->isLocked($weekStart->addDays(6)->toDateString()),
        ]);
    }
}
