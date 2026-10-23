<?php

namespace App\Livewire\Finance;

use App\Actions\Finance\ManageFreelancerInvoice;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\FreelancerInvoice;
use App\Services\Approvals\ApprovalEngine;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Freelancers send invoices for approved time (or self-bill); finance checks
 * them against approved time and approves them for payment.
 */
#[Layout('layouts.app')]
#[Title('Freelancer invoices')]
class FreelancerInvoices extends Component
{
    use UsesWorkspace, WithFileUploads;

    /** @var array{period_start: string, period_end: string, invoice_number: string, net: string, vat: string} */
    public array $invoiceForm = ['period_start' => '', 'period_end' => '', 'invoice_number' => '', 'net' => '', 'vat' => ''];

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    /** @var array{person_id: string, period_start: string, period_end: string} */
    public array $selfBill = ['person_id' => '', 'period_start' => '', 'period_end' => ''];

    public ?int $decidingId = null;

    public string $note = '';

    public function mount(): void
    {
        $month = $this->workspace()->today()->subMonth();
        $this->invoiceForm['period_start'] = $this->selfBill['period_start'] = $month->startOfMonth()->toDateString();
        $this->invoiceForm['period_end'] = $this->selfBill['period_end'] = $month->endOfMonth()->toDateString();

        if (auth()->user()->can('freelancer_invoices.submit')) {
            $this->selfBill['person_id'] = (string) auth()->user()->person_id;
        }
    }

    public function submitInvoice(ManageFreelancerInvoice $manage): void
    {
        $this->resetValidation();

        try {
            $invoice = $manage->upload(auth()->user(), $this->invoiceForm + ['file' => $this->upload]);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => [($k === 'file' ? 'upload' : 'invoiceForm.'.$k) => $m])->all());

            return;
        }

        $this->reset('upload');
        $this->invoiceForm['invoice_number'] = $this->invoiceForm['net'] = $this->invoiceForm['vat'] = '';
        $this->toast($invoice->difference() === 0 ? 'Invoice sent. It matches your approved time.' : 'Invoice sent. It differs from your approved time, so finance will check it.');
    }

    public function raiseSelfBill(ManageFreelancerInvoice $manage): void
    {
        $this->resetValidation();

        try {
            $invoice = $manage->selfBill(auth()->user(), (int) $this->selfBill['person_id'], $this->selfBill['period_start'], $this->selfBill['period_end']);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['selfBill.'.$k => $m])->all());

            return;
        }

        $this->toast("Raised {$invoice->invoice_number} from approved time.");
    }

    public function startDecision(int $id): void
    {
        $this->resetValidation();
        $this->decidingId = $id;
        $this->note = '';
        $this->dispatch('modal', id: 'fi-modal', show: true);
    }

    public function decide(bool $approve, ManageFreelancerInvoice $manage): void
    {
        $this->resetValidation();

        try {
            $manage->decide(auth()->user(), (int) $this->decidingId, $approve, $this->note ?: null);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->dispatch('modal', id: 'fi-modal', show: false);
        $this->toast($approve ? 'Approved for payment.' : 'Queried. They have been told.');
    }

    public function markPaid(int $id, ManageFreelancerInvoice $manage): void
    {
        $this->attempt(fn () => $manage->markPaid(auth()->user(), $id, $this->workspace()->today()->toDateString()), 'Recorded as paid.');
    }

    public function render(ManageFreelancerInvoice $manage, ApprovalEngine $engine): View
    {
        $user = auth()->user();
        $workspace = $this->workspace();
        $isFinance = $user->can('freelancer_invoices.approve');
        $mine = $user->can('freelancer_invoices.submit');

        // Finance and the operations director see all; producers, freelancers on
        // their events; freelancers, their own.
        $crewOnMyEvents = $workspace->assignments()
            ->filter(fn ($a) => $this->viewer()->isProducerOf($workspace->event($a['event_id']) ?? ['producer_id' => null]))
            ->pluck('person_id')->unique()->all();

        $invoices = FreelancerInvoice::query()->orderByDesc('period_end')->orderByDesc('id')->get()
            ->filter(fn (FreelancerInvoice $i) => $isFinance || $user->can('budgets.view_all') || $i->person_id === $user->person_id
                || ($user->can('freelancer_invoices.view') && in_array($i->person_id, $crewOnMyEvents, true)));

        $deciding = $this->decidingId ? FreelancerInvoice::query()->find($this->decidingId) : null;
        $start = $this->selfBill['period_start'];
        $end = $this->selfBill['period_end'];
        $selfBillPerson = (int) $this->selfBill['person_id'];

        return view('livewire.finance.freelancer-invoices', [
            'invoices' => $invoices,
            'isFinance' => $isFinance,
            'mine' => $mine,
            'decidable' => $invoices->filter(fn (FreelancerInvoice $i) => $engine->decidableBy($user->person_id, $i) !== null)->pluck('id')->all(),
            'deciding' => $deciding,
            'selfBilling' => config('hive.finance.self_billing'),
            'selfBillPeople' => $isFinance
                ? $workspace->people()->where('employment_type', 'freelancer')->filter(fn ($p) => $manage->hasAgreement($p['id']))->mapWithKeys(fn ($p) => [$p['id'] => $workspace->personName($p['id'])])
                : collect($mine && $manage->hasAgreement((int) $user->person_id) ? [$user->person_id => $workspace->personName($user->person_id)] : []),
            'selfBillPreview' => $selfBillPerson && $start !== '' && $end !== '' && $start <= $end
                ? $workspace->approvedTimeCost($selfBillPerson, $start, $end) : null,
            'expectedMine' => $mine && $this->invoiceForm['period_start'] !== '' && $this->invoiceForm['period_end'] !== '' && $this->invoiceForm['period_start'] <= $this->invoiceForm['period_end']
                ? $workspace->approvedTimeCost((int) $user->person_id, $this->invoiceForm['period_start'], $this->invoiceForm['period_end']) : null,
            'today' => CarbonImmutable::parse($workspace->today()->toDateString()),
        ]);
    }
}
