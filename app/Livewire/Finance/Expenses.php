<?php

namespace App\Livewire\Finance;

use App\Actions\Finance\ManageExpense;
use App\Enums\ApprovalStatus;
use App\Enums\ExpenseCategory;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\Expense;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Expenses')]
class Expenses extends Component
{
    use UsesWorkspace, WithFileUploads;

    /** @var array{event_id: string, category: string, date: string, description: string, amount: string, currency: string, vat: string, miles: string} */
    public array $claim = ['event_id' => '', 'category' => 'taxi', 'date' => '', 'description' => '', 'amount' => '', 'currency' => 'GBP', 'vat' => '', 'miles' => ''];

    /** @var TemporaryUploadedFile|null */
    public $receipt = null;

    public ?int $decidingId = null;

    public string $comment = '';

    public function mount(): void
    {
        $this->claim['date'] = $this->workspace()->today()->toDateString();
    }

    public function submit(ManageExpense $manage): void
    {
        $this->resetValidation();

        try {
            $expense = $manage->submit(auth()->user(), array_map(fn ($v) => $v === '' ? null : $v, $this->claim) + ['receipt' => $this->receipt]);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => [($k === 'receipt' ? 'receipt' : 'claim.'.$k) => $m])->all());

            return;
        }

        $this->reset('receipt');
        $this->claim = ['event_id' => $this->claim['event_id'], 'category' => $this->claim['category'], 'date' => $this->claim['date'], 'description' => '', 'amount' => '', 'currency' => 'GBP', 'vat' => '', 'miles' => ''];
        $this->toast('Claim sent for '.Money::of($expense->amount_minor, $expense->currency)->format().'. The producer approves it first, then finance.');
    }

    public function startDecision(int $id): void
    {
        $this->resetValidation();
        $this->decidingId = $id;
        $this->comment = '';
        $this->dispatch('modal', id: 'expense-modal', show: true);
    }

    public function decide(bool $approve, ManageExpense $manage): void
    {
        $this->resetValidation();

        try {
            $outcome = $manage->decide(auth()->user(), (int) $this->decidingId, $approve, $this->comment ?: null);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->dispatch('modal', id: 'expense-modal', show: false);
        $this->toast(match ($outcome) {
            ApprovalStatus::Approved => 'Approved. It is now an actual cost on the event.',
            ApprovalStatus::Pending => 'Approved. Finance approves it next.',
            default => 'Rejected. They have been told why.',
        });
    }

    public function render(ApprovalEngine $engine): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();
        $user = auth()->user();
        $waitingForMe = $user->person_id ? $engine->inbox($user->person_id)->where('approvable_type', Expense::class)->pluck('approvable_id')->all() : [];

        $expenses = $workspace->expenses()
            ->filter(function ($x) use ($user, $viewer, $workspace, $waitingForMe) {
                if ($viewer->isSelf($x['person_id']) || $user->can('expenses.view_all') || in_array($x['id'], $waitingForMe, true)) {
                    return true;
                }

                $event = $workspace->event($x['event_id']);

                return $user->can('expenses.approve') && $event && $viewer->isProducerOf($event);
            })
            ->sortByDesc('date');

        $deciding = $this->decidingId ? $workspace->expenses()->get($this->decidingId) : null;

        $myEvents = $user->person_id ? $workspace->events()
            ->filter(fn ($e) => $workspace->isOnEvent($e['id'], $user->person_id) && ! in_array($e['stage'], ['cancelled', 'lost', 'closed', 'reconciled'], true))
            ->sortBy('code') : collect();

        return view('livewire.finance.expenses', [
            'expenses' => $expenses,
            'waitingForMe' => $waitingForMe,
            'deciding' => $deciding,
            'canClaim' => $user->can('expenses.submit'),
            'myEvents' => $myEvents,
            'categories' => ExpenseCategory::cases(),
            'currencies' => config('hive.finance.currencies'),
            'mileageRate' => Money::of((int) config('hive.finance.mileage_rate_minor', 45)),
        ]);
    }
}
