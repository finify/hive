<?php

namespace App\Actions\Finance;

use App\Actions\People\UploadDocument;
use App\Demo\Rows;
use App\Demo\Workspace;
use App\Enums\ApprovalStatus;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Expenses: claimed with a receipt (or miles, at the configured rate),
 * approved by the event's producer and then finance, then an Actual cost on
 * the event in the matching budget category (or contingency, if the budget
 * has no line in that category).
 */
class ManageExpense
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ApprovalEngine $approvals,
        private readonly PostCost $costs,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function submit(User $user, array $input): Expense
    {
        abort_unless($user->can('expenses.submit') && $user->person_id !== null, 403);

        $mileage = ($input['category'] ?? null) === ExpenseCategory::Mileage->value;

        $data = Validator::make($input, [
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'date' => ['required', 'date', 'before_or_equal:'.$this->workspace->today()->toDateString()],
            'description' => ['required', 'string', 'max:255'],
            'miles' => [$mileage ? 'required' : 'nullable', 'numeric', 'min:0.1', 'max:5000'],
            'amount' => [$mileage ? 'nullable' : 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'currency' => [$mileage ? 'nullable' : 'required', Rule::in(config('hive.finance.currencies'))],
            'vat' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'receipt' => [$mileage ? 'nullable' : 'required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.UploadDocument::MAX_KILOBYTES],
        ], [
            'receipt.required' => 'Attach the receipt.',
            'amount.regex' => 'Enter the amount, for example 38.40.',
            'date.before_or_equal' => 'Claim spending that has happened.',
        ])->validate();

        if (isset($data['event_id']) && ! $this->workspace->isOnEvent((int) $data['event_id'], $user->person_id)) {
            throw ValidationException::withMessages(['event_id' => 'Claim only against events you work on.']);
        }

        if ($mileage) {
            $tenths = (int) round(((float) $data['miles']) * 10);
            $amount = Money::of((int) config('hive.finance.mileage_rate_minor', 45))->multiplyRatio($tenths, 10);
            $vat = Money::zero();
        } else {
            $amount = Money::parse((string) $data['amount'], $data['currency']);
            $vat = filled($data['vat'] ?? null) ? Money::parse((string) $data['vat'], $data['currency']) : Money::zero($data['currency']);
        }

        if ($vat->greaterThan($amount)) {
            throw ValidationException::withMessages(['vat' => 'VAT can’t be more than the amount.']);
        }

        $rate = $amount->currency === 'GBP' ? null : $this->workspace->exchangeRate($amount->currency);

        $expense = DB::transaction(function () use ($user, $data, $amount, $vat, $rate, $mileage) {
            /** @var UploadedFile|null $file */
            $file = $data['receipt'] ?? null;

            $expense = Expense::query()->create([
                'person_id' => $user->person_id, 'event_id' => $data['event_id'] ?? null, 'category' => $data['category'], 'date' => $data['date'],
                'description' => $data['description'], 'amount_minor' => $amount->minor, 'currency' => $amount->currency, 'fx_rate' => $rate,
                'amount_gbp_minor' => $this->costs->toGbp($amount, $rate)->minor, 'vat_minor' => $vat->minor,
                'mileage_miles' => $mileage ? $data['miles'] : null,
                'receipt_path' => $file?->store("documents/receipts/{$user->person_id}", config('filesystems.documents_disk', 'local')),
                'receipt_name' => $file?->getClientOriginalName(), 'status' => ExpenseStatus::Pending, 'submitted_at' => now(),
            ]);

            $this->approvals->request($expense);

            return $expense;
        });

        $this->workspace->forget();

        activity('finance')
            ->causedBy($user)
            ->performedOn($expense)
            ->withProperties(['ref' => 'EX-'.$expense->id, 'attributes' => ['amount' => $amount->format(), 'category' => $data['category'], 'event_id' => $expense->event_id]])
            ->event('expense-submitted')
            ->log("Claimed {$amount->format()}: {$expense->description}");

        $first = $this->approvals->inboxApproverIds($expense);

        foreach ($first as $approver) {
            $this->workspace->notify($approver, 'approval', 'Expense to approve', $this->workspace->personName($user->person_id).", {$amount->format()}: {$expense->description}.", 'approvals');
        }

        return $expense;
    }

    public function decide(User $user, int $expenseId, bool $approve, ?string $comment = null): ApprovalStatus
    {
        $expense = Expense::query()->where('status', ExpenseStatus::Pending)->findOrFail($expenseId);

        if (! $approve) {
            Validator::make(['comment' => $comment], ['comment' => ['required', 'string', 'min:5', 'max:500']], ['comment.required' => 'Say why it is rejected.', 'comment.min' => 'Say why it is rejected.'])->validate();
        }

        $outcome = DB::transaction(function () use ($user, $expense, $approve, $comment) {
            $outcome = $this->approvals->decide((int) $user->person_id, $expense, $approve, $comment);

            if ($outcome === ApprovalStatus::Approved) {
                $expense->update(['status' => ExpenseStatus::Approved, 'decided_at' => now(), 'decision_comment' => $comment]);

                if ($expense->event_id !== null) {
                    $lines = Rows::of($this->workspace->budget($expense->event_id)['lines'] ?? []);
                    $line = $lines->firstWhere('category', $expense->category->costCategory()) ?? $lines->firstWhere('category', 'contingency');
                    $this->costs->actual($expense->event_id, $line['id'] ?? null, 'expense', $expense->id, 'EX-'.$expense->id, Money::of($expense->amount_minor, $expense->currency), $expense->fx_rate, true);
                }
            } elseif ($outcome === ApprovalStatus::Rejected) {
                $expense->update(['status' => ExpenseStatus::Rejected, 'decided_at' => now(), 'decision_comment' => $comment]);
            }

            return $outcome;
        });

        $this->workspace->forget();
        $amount = Money::of($expense->amount_minor, $expense->currency)->format();

        activity('finance')
            ->causedBy($user)
            ->performedOn($expense)
            ->withProperties(['ref' => 'EX-'.$expense->id, 'old' => ['status' => ExpenseStatus::Pending->value], 'attributes' => ['status' => $expense->status->value, 'step' => $outcome->value, 'comment' => $comment]])
            ->event($approve ? 'expense-approved' : 'expense-rejected')
            ->log(($approve ? ($outcome === ApprovalStatus::Approved ? 'Approved' : 'Approved, for finance next,') : 'Rejected')." the {$amount} expense from ".$this->workspace->personName($expense->person_id));

        if ($outcome === ApprovalStatus::Pending) {
            foreach ($this->approvals->inboxApproverIds($expense) as $approver) {
                $this->workspace->notify($approver, 'approval', 'Expense to approve', $this->workspace->personName($expense->person_id).", {$amount}: {$expense->description}.", 'approvals');
            }
        } else {
            $this->workspace->notify($expense->person_id, 'approval', $outcome === ApprovalStatus::Approved ? 'Expense approved' : 'Expense rejected', "{$amount}: {$expense->description}".($comment ? ". {$comment}" : '.'), 'my-work');
        }

        return $outcome;
    }
}
