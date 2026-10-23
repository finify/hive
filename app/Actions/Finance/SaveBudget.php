<?php

namespace App\Actions\Finance;

use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Enums\BudgetStatus;
use App\Enums\RevenueType;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CostEntry;
use App\Models\PurchaseOrder;
use App\Models\RevenueLine;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Changes to an event's budget and revenue. Editing an approved budget
 * starts a revision (a Draft version); approving it makes it the new
 * baseline and keeps the old one as history.
 */
class SaveBudget
{
    public const CATEGORIES = ['venue', 'production', 'av', 'creative', 'print', 'catering', 'crew', 'travel', 'accommodation', 'freight', 'registration', 'contingency', 'agency-fee'];

    public function __construct(
        private readonly Workspace $workspace,
        private readonly PostCost $costs,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveLine(User $user, int $eventId, array $input, ?BudgetLine $line = null): BudgetLine
    {
        $this->authoriseEdit($user, $eventId);
        abort_if($line !== null && $line->event_id !== $eventId, 404);

        $data = Validator::make($input, [
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'description' => ['required', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'currency' => ['required', Rule::in(config('hive.finance.currencies'))],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['amount.regex' => 'Enter an amount, for example 12000 or 12000.50.'])->validate();

        $amount = Money::parse((string) $data['amount'], $data['currency']);
        $rate = $data['currency'] === 'GBP' ? null : $this->workspace->exchangeRate($data['currency']);

        if ($data['currency'] !== 'GBP' && $rate === null) {
            throw ValidationException::withMessages(['currency' => "Finance hasn’t entered a {$data['currency']} rate yet."]);
        }

        $gbp = $this->costs->toGbp($amount, $rate);
        $creating = $line === null;
        $old = $line?->toWorkspaceArray();

        $line = DB::transaction(function () use ($eventId, $line, $data, $amount, $rate, $gbp) {
            $this->startRevision($eventId);
            $line ??= new BudgetLine(['event_id' => $eventId, 'remaining_gbp_minor' => 0, 'sort_order' => (int) BudgetLine::query()->where('event_id', $eventId)->max('sort_order') + 1]);

            // The remaining estimate moves with the budget; it never goes below zero.
            $remaining = max(0, $line->remaining_gbp_minor + $gbp->minor - ($line->budget_gbp_minor ?? 0));

            $line->fill([
                'category' => $data['category'], 'description' => $data['description'], 'supplier_id' => $data['supplier_id'] ?? null,
                'budget_minor' => $amount->minor, 'currency' => $data['currency'], 'fx_rate' => $rate, 'budget_gbp_minor' => $gbp->minor,
                'remaining_gbp_minor' => $remaining, 'notes' => $data['notes'] ?? null,
            ])->save();

            return $line;
        });

        $this->workspace->forget();
        $code = $this->workspace->event($eventId)['code'] ?? '';

        activity('finance')
            ->causedBy($user)
            ->performedOn($line)
            ->withProperties(['ref' => $code, 'old' => $old ? ['budget' => Money::of($old['budget_gbp_minor'])->format(), 'description' => $old['description']] : [], 'attributes' => ['budget' => $gbp->format(), 'description' => $line->description, 'category' => $line->category]])
            ->event($creating ? 'budget-line-added' : 'budget-line-updated')
            ->log(($creating ? 'Added the budget line ' : 'Changed the budget line ')."“{$line->description}” ({$gbp->format()}) on {$code}");

        return $line;
    }

    public function removeLine(User $user, BudgetLine $line): void
    {
        $this->authoriseEdit($user, $line->event_id);

        if (CostEntry::query()->where('budget_line_id', $line->id)->exists() || PurchaseOrder::query()->where('budget_line_id', $line->id)->exists()) {
            throw ValidationException::withMessages(['line' => 'This line has spend against it. Set its budget to zero instead.']);
        }

        DB::transaction(function () use ($line) {
            $this->startRevision($line->event_id);
            $line->delete();
        });

        $this->workspace->forget();

        activity('finance')
            ->causedBy($user)
            ->withProperties(['ref' => $this->workspace->event($line->event_id)['code'] ?? null, 'old' => ['description' => $line->description, 'budget' => Money::of($line->budget_gbp_minor)->format()]])
            ->event('budget-line-removed')
            ->log("Removed the budget line “{$line->description}”");
    }

    /**
     * Approves the current draft as the new baseline.
     */
    public function approve(User $user, int $eventId): Budget
    {
        abort_unless($user->can('budgets.approve'), 403);
        $draft = Budget::query()->where('event_id', $eventId)->where('status', BudgetStatus::Draft)->latest('version')->first();

        if ($draft === null) {
            throw ValidationException::withMessages(['budget' => 'There is no revision waiting for approval.']);
        }

        $lines = BudgetLine::query()->where('event_id', $eventId)->orderBy('sort_order')->get()->map(fn (BudgetLine $l) => $l->toWorkspaceArray())->values()->all();
        $total = (int) array_sum(array_column($lines, 'budget_gbp_minor'));
        $baseline = Budget::query()->where('event_id', $eventId)->where('status', BudgetStatus::Approved)->first();

        DB::transaction(function () use ($draft, $baseline, $lines, $total, $user) {
            $baseline?->update(['status' => BudgetStatus::Revised]);
            $draft->update(['status' => BudgetStatus::Approved, 'total_gbp_minor' => $total, 'lines_snapshot' => $lines, 'approved_by' => $user->person_id, 'approved_at' => now()]);
        });

        $this->workspace->forget();
        $code = $this->workspace->event($eventId)['code'] ?? '';

        activity('finance')
            ->causedBy($user)
            ->performedOn($draft)
            ->withProperties(['ref' => $code, 'old' => ['baseline' => $baseline ? Money::of($baseline->total_gbp_minor)->format() : null, 'version' => $baseline?->version], 'attributes' => ['baseline' => Money::of($total)->format(), 'version' => $draft->version]])
            ->event('budget-approved')
            ->log("Approved budget version {$draft->version} for {$code}: ".Money::of($total)->format());

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function addRevenue(User $user, int $eventId, array $input): RevenueLine
    {
        $this->authoriseEdit($user, $eventId);

        $data = Validator::make($input, [
            'type' => ['required', Rule::enum(RevenueType::class)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ], ['amount.regex' => 'Enter an amount, for example 12000 or 12000.50.'])->validate();

        $amount = Money::parse((string) $data['amount']);
        $row = RevenueLine::query()->create(['event_id' => $eventId, 'type' => $data['type'], 'description' => $data['description'], 'amount_minor' => $amount->minor, 'currency' => 'GBP']);
        $this->workspace->forget();

        activity('finance')
            ->causedBy($user)
            ->performedOn($row)
            ->withProperties(['ref' => $this->workspace->event($eventId)['code'] ?? null, 'attributes' => ['type' => $data['type'], 'amount' => $amount->format()]])
            ->event('revenue-added')
            ->log("Added revenue: {$data['description']}, {$amount->format()}");

        return $row;
    }

    public function removeRevenue(User $user, RevenueLine $row): void
    {
        $this->authoriseEdit($user, $row->event_id);
        $row->delete();
        $this->workspace->forget();

        activity('finance')
            ->causedBy($user)
            ->withProperties(['ref' => $this->workspace->event($row->event_id)['code'] ?? null, 'old' => ['description' => $row->description, 'amount' => Money::of($row->amount_minor)->format()]])
            ->event('revenue-removed')
            ->log("Removed revenue: {$row->description}");
    }

    public function canEdit(User $user, int $eventId): bool
    {
        $event = $this->workspace->event($eventId);

        return $event !== null && $user->can('budgets.edit') && (new Viewer($user, $this->workspace))->canSeeBudget($event);
    }

    private function authoriseEdit(User $user, int $eventId): void
    {
        abort_unless($this->canEdit($user, $eventId), 403);
    }

    /**
     * The first change after approval opens the next version as a Draft.
     */
    private function startRevision(int $eventId): void
    {
        $latest = Budget::query()->where('event_id', $eventId)->latest('version')->first();

        if ($latest === null || $latest->status !== BudgetStatus::Draft) {
            Budget::query()->create(['event_id' => $eventId, 'version' => ($latest->version ?? 0) + 1, 'status' => BudgetStatus::Draft, 'total_gbp_minor' => 0]);
        }
    }
}
