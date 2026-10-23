<?php

use App\Actions\Finance\ExportFinance;
use App\Actions\Finance\ManageBilling;
use App\Actions\Finance\ManageExpense;
use App\Actions\Finance\ManageFreelancerInvoice;
use App\Actions\Finance\SaveBudget;
use App\Enums\BudgetStatus;
use App\Enums\Role;
use App\Livewire\Events\Budget as BudgetTab;
use App\Livewire\Finance\Exports;
use App\Models\BillingMilestone;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Expense;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(fn () => Storage::fake('local'));

it('starts a revision when an approved budget changes, and keeps the baseline as history', function () {
    $before = $this->workspace()->financials(7);

    Livewire::actingAs(persona('hannah'))->test(BudgetTab::class, ['eventId' => 7])
        ->call('startLine')
        ->set('lineForm.category', 'print')
        ->set('lineForm.description', 'Extra wayfinding for hall 3')
        ->set('lineForm.amount', '1500')
        ->call('saveLine')
        ->assertHasNoErrors()
        ->assertSee('is a revision');

    $draft = Budget::query()->where('event_id', 7)->where('status', BudgetStatus::Draft)->firstOrFail();
    $after = $this->workspace()->financials(7);

    // The new line's budget is all still to spend, so it adds to budget and forecast alike.
    expect($after->budget->minus($before->budget)->minor)->toBe(150000)
        ->and($after->forecast()->minus($before->forecast())->minor)->toBe(150000);

    // Producers can't approve budgets; the operations director can.
    expect(fn () => app(SaveBudget::class)->approve(persona('hannah'), 7))->toThrow(HttpException::class);

    app(SaveBudget::class)->approve(persona('priya'), 7);

    expect($draft->refresh()->status)->toBe(BudgetStatus::Approved)
        ->and($draft->total_gbp_minor)->toBe($after->budget->minor)
        ->and(Budget::query()->where('event_id', 7)->where('status', BudgetStatus::Revised)->count())->toBe(1);
});

it('lowers the remaining estimate with the budget, never below zero', function () {
    $line = BudgetLine::query()->findOrFail(5);
    app(SaveBudget::class)->saveLine(persona('hannah'), 7, ['category' => 'creative', 'description' => $line->description, 'amount' => '23000', 'currency' => 'GBP'], $line);

    expect($line->refresh()->remaining_gbp_minor)->toBe(0);
});

it('won’t remove a line with spend against it', function () {
    expect(fn () => app(SaveBudget::class)->removeLine(persona('hannah'), BudgetLine::query()->findOrFail(1)))->toThrow(ValidationException::class);
});

it('adds a change request to revenue, which moves the margin', function () {
    $before = $this->workspace()->financials(7);
    app(SaveBudget::class)->addRevenue(persona('hannah'), 7, ['type' => 'change-request', 'description' => 'Extra breakout room', 'amount' => '4000']);

    expect($this->workspace()->financials(7)->margin()->minus($before->margin())->minor)->toBe(400000);
});

it('checks a freelancer invoice against approved time and needs a note to approve a difference', function () {
    // Arjun's approved October time.
    $expected = $this->workspace()->approvedTimeCost(31, '2026-10-01', '2026-10-31');
    $arjun = User::factory()->withRole(Role::Freelancer)->create(['person_id' => 31]);

    $invoice = app(ManageFreelancerInvoice::class)->upload($arjun, [
        'period_start' => '2026-10-01', 'period_end' => '2026-10-23', 'invoice_number' => 'AM-1010',
        'net' => number_format(($expected->minor + 5000) / 100, 2, '.', ''), 'file' => UploadedFile::fake()->create('AM-1010.pdf', 80, 'application/pdf'),
    ]);

    expect($invoice->expected_minor)->toBe($expected->minor)->and($invoice->difference())->toBe(5000);

    expect(fn () => app(ManageFreelancerInvoice::class)->decide(persona('grace'), $invoice->id, true))->toThrow(ValidationException::class);
    app(ManageFreelancerInvoice::class)->decide(persona('grace'), $invoice->id, true, 'Includes £50 parking agreed with Hannah.');
    expect($invoice->refresh()->status->value)->toBe('approved');

    // Overlapping periods are refused.
    expect(fn () => app(ManageFreelancerInvoice::class)->upload($arjun, ['period_start' => '2026-10-15', 'period_end' => '2026-10-20', 'invoice_number' => 'AM-1011', 'net' => '100', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]))
        ->toThrow(ValidationException::class);
});

it('self-bills only freelancers with a self-billing agreement', function () {
    $invoice = app(ManageFreelancerInvoice::class)->selfBill(persona('grace'), 31, '2026-10-01', '2026-10-23');

    expect($invoice->is_self_billed)->toBeTrue()
        ->and($invoice->difference())->toBe(0)
        ->and($invoice->net_minor)->toBe($this->workspace()->approvedTimeCost(31, '2026-10-01', '2026-10-23')->minor);

    // Kofi has no agreement.
    expect(fn () => app(ManageFreelancerInvoice::class)->selfBill(persona('grace'), 26, '2026-10-01', '2026-10-23'))->toThrow(ValidationException::class);
});

it('pays mileage at the configured rate and needs a receipt for everything else', function () {
    $manage = app(ManageExpense::class);
    $mileage = $manage->submit(persona('jamie'), ['category' => 'mileage', 'date' => '2026-10-20', 'description' => 'Office to Riverside and back', 'miles' => '46']);

    expect($mileage->amount_minor)->toBe(2070);

    expect(fn () => $manage->submit(persona('jamie'), ['category' => 'parking', 'date' => '2026-10-20', 'description' => 'Riverside car park', 'amount' => '18', 'currency' => 'GBP']))
        ->toThrow(ValidationException::class, 'Attach the receipt.');
});

it('rejects an expense with a reason and tells the person', function () {
    $expense = Expense::query()->where('status', 'pending')->where('event_id', 7)->firstOrFail();

    expect(fn () => app(ManageExpense::class)->decide(persona('hannah'), $expense->id, false))->toThrow(ValidationException::class);
    app(ManageExpense::class)->decide(persona('hannah'), $expense->id, false, 'Materials were on the scenic PO.');

    expect($expense->refresh()->status->value)->toBe('rejected')
        ->and(Person::query()->findOrFail($expense->person_id)->notifications()->latest()->first()->data['title'])->toBe('Expense rejected');
});

it('tracks billing milestones from planned to paid', function () {
    $billing = app(ManageBilling::class);
    $milestone = $billing->addMilestone(persona('grace'), ['event_id' => 9, 'description' => 'Balance on reconciliation', 'percentage' => 10, 'due_date' => '2026-12-15']);

    expect($milestone->amount_minor)->toBe($this->workspace()->revenue(9)->percentage(1000)->minor);

    $billing->markInvoiced(persona('grace'), $milestone->id, 'INV-3190', '2026-10-23');
    $billing->markPaid(persona('grace'), $milestone->id, '2026-10-23');
    expect(BillingMilestone::query()->findOrFail($milestone->id)->status->value)->toBe('paid');

    expect(fn () => $billing->addMilestone(persona('hannah'), ['event_id' => 9, 'description' => 'x', 'amount' => '10', 'due_date' => '2026-12-15']))
        ->toThrow(HttpException::class);
});

it('exports approved supplier invoices as CSV and logs it', function () {
    $response = $this->actingAs(persona('grace'))->get(route('exports.download', ['type' => 'supplier-invoices', 'from' => '2026-01-01', 'to' => '2026-12-31']));

    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('Invoice,Supplier')->toContain('RCC-55120');
    $this->assertDatabaseHas('activity_log', ['event' => 'exported', 'causer_id' => persona('grace')->id]);
});

it('keeps day rates out of exports for anyone without rates.view (rule 17)', function () {
    $export = app(ExportFinance::class);

    expect($export->can(persona('grace'), 'freelancer-costs'))->toBeTrue()
        ->and($export->can(persona('hannah'), 'freelancer-costs'))->toBeFalse();

    $this->actingAs(persona('hannah'))->get(route('exports.download', ['type' => 'freelancer-costs', 'from' => '2026-01-01', 'to' => '2026-12-31']))->assertForbidden();

    // Someone with billing access but no rates sees the other exports only.
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('billing.view');
    expect($export->can($viewer, 'freelancer-costs'))->toBeFalse()->and($export->can($viewer, 'billing'))->toBeTrue();
});

it('guards CSV cells against spreadsheet formulas', function () {
    Expense::query()->create([
        'person_id' => 10, 'event_id' => 7, 'category' => 'other', 'date' => '2026-10-21', 'description' => '=HYPERLINK("http://example.test")',
        'amount_minor' => 1000, 'currency' => 'GBP', 'amount_gbp_minor' => 1000, 'status' => 'approved',
    ]);

    $csv = $this->actingAs(persona('grace'))->get(route('exports.download', ['type' => 'expenses', 'from' => '2026-10-01', 'to' => '2026-10-31']))->streamedContent();
    expect($csv)->toContain("'=HYPERLINK");
});

it('lets finance set an exchange rate that new costs use', function () {
    Livewire::actingAs(persona('grace'))->test(Exports::class)
        ->set('rate', ['currency' => 'EUR', 'rate_to_gbp' => '0.8700', 'effective_on' => '2026-10-23'])
        ->call('setRate')
        ->assertHasNoErrors();

    $this->workspace()->forget();
    expect($this->workspace()->exchangeRate('EUR'))->toBe('0.8700');
});
