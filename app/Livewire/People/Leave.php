<?php

namespace App\Livewire\People;

use App\Actions\Leave\ManageBankHolidays;
use App\Actions\Leave\RecordAbsence;
use App\Actions\Leave\SetLeaveAllowance;
use App\Actions\People\RequestLeave;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\BankHoliday;
use App\Models\LeaveAllowance;
use App\Models\Person;
use App\Services\Leave\LeaveBook;
use App\Support\Display;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Leave')]
class Leave extends Component
{
    use UsesWorkspace;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    /** @var array{leave_type: string, start_date: string, end_date: string, reason: string} */
    public array $leaveForm = ['leave_type' => 'annual', 'start_date' => '', 'end_date' => '', 'reason' => ''];

    /** @var array{person_id: string, leave_type: string, start_date: string, end_date: string, reason: string} */
    public array $absenceForm = ['person_id' => '', 'leave_type' => 'sick', 'start_date' => '', 'end_date' => '', 'reason' => ''];

    /** @var array{person_id: int, days_allowed: string, days_carried_over: string} */
    public array $allowanceForm = ['person_id' => 0, 'days_allowed' => '', 'days_carried_over' => ''];

    /** @var array{region: string, date: string, name: string} */
    public array $holidayForm = ['region' => 'england-and-wales', 'date' => '', 'name' => ''];

    public function requestLeave(RequestLeave $requestLeave): void
    {
        if ($this->submit(fn () => $requestLeave->handle(auth()->user(), $this->leaveForm), 'leaveForm', $leave)) {
            $this->leaveForm = ['leave_type' => 'annual', 'start_date' => '', 'end_date' => '', 'reason' => ''];
            $this->toast('Leave requested: '.Display::days((float) $leave->days).'. Your manager has been told.');
        }
    }

    public function recordAbsence(RecordAbsence $record): void
    {
        if ($this->submit(fn () => $record->handle(auth()->user(), $this->absenceForm), 'absenceForm', $leave)) {
            $this->absenceForm = ['person_id' => '', 'leave_type' => 'sick', 'start_date' => '', 'end_date' => '', 'reason' => ''];
            $this->toast('Absence recorded: '.Display::days((float) $leave->days).'.');
        }
    }

    public function editAllowance(int $personId, LeaveBook $book): void
    {
        $this->resetValidation();
        $row = LeaveAllowance::query()->where('person_id', $personId)->where('leave_year', $book->yearOf($book->today()))->first();
        $this->allowanceForm = [
            'person_id' => $personId,
            'days_allowed' => (string) (float) ($row->days_allowed ?? config('hive.leave.default_allowance')),
            'days_carried_over' => (string) (float) ($row->days_carried_over ?? 0),
        ];
        $this->dispatch('modal', id: 'allowance-modal', show: true);
    }

    public function saveAllowance(SetLeaveAllowance $set, LeaveBook $book): void
    {
        $form = $this->allowanceForm;

        if ($this->submit(fn () => $set->handle(auth()->user(), (int) $form['person_id'], $book->yearOf($book->today()), $form), 'allowanceForm')) {
            $this->dispatch('modal', id: 'allowance-modal', show: false);
            $this->toast('Allowance saved for '.$this->workspace()->personName((int) $form['person_id']).'.');
        }
    }

    public function addHoliday(ManageBankHolidays $manage): void
    {
        if ($this->submit(fn () => $manage->add(auth()->user(), $this->holidayForm), 'holidayForm')) {
            $this->holidayForm = ['region' => $this->holidayForm['region'], 'date' => '', 'name' => ''];
            $this->toast('Bank holiday added.');
        }
    }

    public function removeHoliday(int $id, ManageBankHolidays $manage): void
    {
        $this->attempt(fn () => $manage->remove(auth()->user(), $id), 'Bank holiday removed.');
    }

    public function cancel(int $leaveId, RequestLeave $requestLeave): void
    {
        $this->attempt(fn () => $requestLeave->cancel(auth()->user(), $leaveId), 'Leave cancelled.');
    }

    /**
     * Runs an Action for a form, putting its validation errors next to the fields.
     */
    private function submit(callable $action, string $form, mixed &$result = null): bool
    {
        $this->resetValidation();

        try {
            $result = $action();
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => [$form.'.'.$k => $m])->all());

            return false;
        }

        return true;
    }

    public function render(LeaveBook $book, RecordAbsence $record): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();
        $today = $workspace->today()->toDateString();
        $personId = $viewer->personId();
        $user = auth()->user();
        $year = $book->yearOf($workspace->today());

        $tabs = array_filter([
            'overview' => 'Who’s off',
            'balances' => $user->can('leave.view_all') ? 'Balances' : null,
            'bank-holidays' => 'Bank holidays',
        ]);

        if (! isset($tabs[$this->tab])) {
            $this->tab = 'overview';
        }

        $calendar = $workspace->leaveRequests()
            ->filter(fn ($l) => $l['end_date'] >= $today && in_array($l['status'], ['approved', 'pending'], true))
            ->filter(fn ($l) => $user->can('leave.view_calendar') || $viewer->isSelf($l['person_id']))
            ->sortBy('start_date');

        // People this user may record absence for: everyone for HR, their reports for a line manager.
        $absentees = Person::query()->where('is_active', true)->whereIn('employment_type', ['employee', 'fixed-term'])
            ->orderBy('first_name')->get()
            ->filter(fn (Person $p) => $record->mayRecordFor($user, $p))
            ->mapWithKeys(fn (Person $p) => [$p->id => $p->first_name.' '.$p->last_name]);

        $balances = $this->tab === 'balances'
            ? Person::query()->where('is_active', true)->whereIn('employment_type', ['employee', 'fixed-term'])->orderBy('first_name')->get()
                ->map(fn (Person $p) => ['person' => $p, 'balance' => $book->balance($p->id, $year)])
            : collect();

        $region = $personId ? $book->regionOf($personId) : 'england-and-wales';

        return view('livewire.people.leave', [
            'tabs' => $tabs,
            'calendar' => $calendar,
            'mine' => $workspace->leaveRequests()->where('person_id', $personId)->sortByDesc('start_date'),
            'balance' => $personId && $user->can('leave.request') ? $book->balance($personId, $year) : null,
            'today' => $today,
            'year' => $year,
            'leaveTypes' => $workspace->organisation()['leave_types'],
            'absentees' => $absentees,
            'balances' => $balances,
            'region' => $region,
            'regions' => BankHoliday::REGIONS,
            'bankHolidays' => BankHoliday::query()->whereDate('date', '>=', $today)
                ->when($this->tab !== 'bank-holidays', fn ($q) => $q->where('region', $region)->limit(4))
                ->orderBy('date')->orderBy('region')->get(),
        ]);
    }
}
