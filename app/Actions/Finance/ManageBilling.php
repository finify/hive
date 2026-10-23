<?php

namespace App\Actions\Finance;

use App\Demo\Workspace;
use App\Enums\BillingStatus;
use App\Models\BillingMilestone;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Client billing milestones (the invoice itself is raised in the accounting
 * system; Hive keeps its reference) and the exchange rates finance enters.
 */
class ManageBilling
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function addMilestone(User $user, array $input): BillingMilestone
    {
        $this->authorise($user);

        $data = Validator::make($input, [
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'description' => ['required', 'string', 'max:255'],
            'percentage' => ['nullable', 'integer', 'between:1,100'],
            'amount' => ['nullable', 'required_without:percentage', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'due_date' => ['required', 'date'],
        ], ['amount.required_without' => 'Enter an amount or a percentage of the fee.'])->validate();

        $amount = filled($data['amount'] ?? null)
            ? Money::parse((string) $data['amount'])
            : $this->workspace->revenue((int) $data['event_id'])->percentage((int) $data['percentage'] * 100);

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'That comes to nothing. Add revenue to the event first, or enter an amount.']);
        }

        $milestone = BillingMilestone::query()->create([
            'event_id' => (int) $data['event_id'], 'description' => $data['description'], 'percentage' => $data['percentage'] ?? null,
            'amount_minor' => $amount->minor, 'currency' => 'GBP', 'due_date' => $data['due_date'], 'status' => BillingStatus::Planned,
        ]);

        $this->workspace->forget();
        $this->log($user, $milestone, null, 'milestone-added', "Added the milestone “{$milestone->description}”, {$amount->format()} due ".$milestone->due_date->format('j M Y'));

        return $milestone;
    }

    public function markInvoiced(User $user, int $id, string $reference, string $on): void
    {
        $this->authorise($user);
        Validator::make(['reference' => $reference, 'on' => $on], ['reference' => ['required', 'string', 'max:40'], 'on' => ['required', 'date']], ['reference.required' => 'Enter the invoice number from the accounting system.'])->validate();

        $milestone = BillingMilestone::query()->whereIn('status', [BillingStatus::Planned, BillingStatus::Due])->findOrFail($id);
        $old = $milestone->status;
        $milestone->update(['status' => BillingStatus::Invoiced, 'invoice_reference' => $reference, 'invoiced_on' => $on]);
        $this->workspace->forget();
        $this->log($user, $milestone, $old, 'milestone-invoiced', "Invoiced “{$milestone->description}” as {$reference}");
    }

    public function markPaid(User $user, int $id, string $on): void
    {
        $this->authorise($user);
        Validator::make(['on' => $on], ['on' => ['required', 'date']])->validate();

        $milestone = BillingMilestone::query()->whereIn('status', [BillingStatus::Invoiced, BillingStatus::Overdue])->findOrFail($id);
        $old = $milestone->status;
        $milestone->update(['status' => BillingStatus::Paid, 'paid_on' => $on]);
        $this->workspace->forget();
        $this->log($user, $milestone, $old, 'milestone-paid', "Recorded “{$milestone->description}” ({$milestone->invoice_reference}) as paid");
    }

    /**
     * @param  array{currency?: mixed, rate_to_gbp?: mixed, effective_on?: mixed}  $input
     */
    public function setRate(User $user, array $input): ExchangeRate
    {
        $this->authorise($user);

        $data = Validator::make($input, [
            'currency' => ['required', Rule::in(array_diff(config('hive.finance.currencies'), ['GBP']))],
            'rate_to_gbp' => ['required', 'regex:/^\d{1,3}\.\d{2,6}$/'],
            'effective_on' => ['required', 'date'],
        ], ['rate_to_gbp.regex' => 'Enter the rate as a decimal, for example 0.8650 (one unit in pounds).'])->validate();

        $previous = $this->workspace->exchangeRate($data['currency']);
        $rate = ExchangeRate::query()->create($data + ['set_by' => $user->person_id]);
        $this->workspace->forget();

        activity('finance')
            ->causedBy($user)
            ->performedOn($rate)
            ->withProperties(['ref' => 'FX-'.$data['currency'], 'old' => ['rate' => $previous], 'attributes' => ['rate' => $data['rate_to_gbp'], 'from' => $data['effective_on']]])
            ->event('rate-set')
            ->log("Set 1 {$data['currency']} = £{$data['rate_to_gbp']} from ".CarbonImmutable::parse($data['effective_on'])->format('j M Y'));

        return $rate;
    }

    public function canManage(User $user): bool
    {
        return $user->can('billing.view') && $user->can('budgets.edit') && $user->can('budgets.view_all');
    }

    private function authorise(User $user): void
    {
        abort_unless($this->canManage($user), 403);
    }

    private function log(User $user, BillingMilestone $milestone, ?BillingStatus $old, string $event, string $message): void
    {
        activity('finance')
            ->causedBy($user)
            ->performedOn($milestone)
            ->withProperties(['ref' => $this->workspace->event($milestone->event_id)['code'] ?? null, 'old' => $old ? ['status' => $old->value] : [], 'attributes' => ['status' => $milestone->status->value]])
            ->event($event)
            ->log($message);
    }
}
