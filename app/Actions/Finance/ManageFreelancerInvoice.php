<?php

namespace App\Actions\Finance;

use App\Actions\People\UploadDocument;
use App\Demo\Workspace;
use App\Enums\DocumentType;
use App\Enums\FreelancerInvoiceStatus;
use App\Enums\VatRate;
use App\Models\Document;
use App\Models\FreelancerInvoice;
use App\Models\Person;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Freelancer invoices for approved time. Option A: the freelancer uploads
 * their own. Option B: Hive raises a self-billed invoice from approved time,
 * when the freelancer has a self-billing agreement on file. Either way the
 * total is checked against approved time at the rate in force each day, and
 * finance approves it for payment. The crew cost was already posted when
 * the time was approved, so invoices don't move the budget.
 */
class ManageFreelancerInvoice
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ApprovalEngine $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function upload(User $user, array $input): FreelancerInvoice
    {
        abort_unless($user->can('freelancer_invoices.submit') && $user->person_id !== null, 403);

        $data = Validator::make($input, [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start', 'before_or_equal:'.$this->workspace->today()->toDateString()],
            'invoice_number' => ['required', 'string', 'max:50'],
            'net' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'vat' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.UploadDocument::MAX_KILOBYTES],
        ], ['file.required' => 'Attach your invoice.', 'net.regex' => 'Enter the net amount, for example 1200 or 1200.50.'])->validate();

        $net = Money::parse((string) $data['net']);
        $vat = filled($data['vat'] ?? null) ? Money::parse((string) $data['vat']) : Money::zero();

        /** @var UploadedFile $file */
        $file = $data['file'];

        return $this->create($user, (int) $user->person_id, $data['period_start'], $data['period_end'], $data['invoice_number'], $net, $vat, false, $file);
    }

    /**
     * Raises a self-billed invoice for the approved time in a period.
     */
    public function selfBill(User $user, int $personId, string $start, string $end): FreelancerInvoice
    {
        abort_unless(config('hive.finance.self_billing'), 404);
        abort_unless(($user->person_id === $personId && $user->can('freelancer_invoices.submit')) || $user->can('freelancer_invoices.approve'), 403);

        Validator::make(['period_start' => $start, 'period_end' => $end], [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start', 'before_or_equal:'.$this->workspace->today()->toDateString()],
        ])->validate();

        if (! $this->hasAgreement($personId)) {
            throw ValidationException::withMessages(['period_start' => 'Self-billing needs a current self-billing agreement on their profile.']);
        }

        $person = Person::query()->findOrFail($personId);
        $net = $this->workspace->approvedTimeCost($personId, $start, $end);

        if ($net->isZero()) {
            throw ValidationException::withMessages(['period_start' => 'There is no approved time to bill in that period.']);
        }

        $vat = $person->vat_registered ? VatRate::Standard->on($net) : Money::zero();
        $number = 'SB-'.$personId.'-'.CarbonImmutable::parse($end)->format('ym').'-'.(FreelancerInvoice::query()->where('person_id', $personId)->where('is_self_billed', true)->count() + 1);

        return $this->create($user, $personId, $start, $end, $number, $net, $vat, true, null);
    }

    /** @var list<int>|null people with a current self-billing agreement */
    private ?array $agreements = null;

    public function hasAgreement(int $personId): bool
    {
        $this->agreements ??= Document::query()->where('documentable_type', Person::class)
            ->where('type', DocumentType::SelfBillingAgreement)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', $this->workspace->today()->toDateString()))
            ->pluck('documentable_id')->map(fn ($id) => (int) $id)->all();

        return in_array($personId, $this->agreements, true);
    }

    public function decide(User $user, int $invoiceId, bool $approve, ?string $note = null): void
    {
        $invoice = FreelancerInvoice::query()->where('status', FreelancerInvoiceStatus::Received)->findOrFail($invoiceId);

        if (! $approve || $invoice->difference() !== 0) {
            Validator::make(['note' => $note], ['note' => ['required', 'string', 'min:5', 'max:500']], [
                'note.required' => $approve ? 'The total differs from approved time. Record why it is accepted.' : 'Say what needs correcting.',
                'note.min' => $approve ? 'The total differs from approved time. Record why it is accepted.' : 'Say what needs correcting.',
            ])->validate();
        }

        DB::transaction(function () use ($user, $invoice, $approve, $note) {
            $this->approvals->decide((int) $user->person_id, $invoice, $approve, $note);
            $invoice->update($approve
                ? ['status' => FreelancerInvoiceStatus::Approved, 'approved_by' => $user->person_id, 'approved_at' => now(), 'notes' => $note ?? $invoice->notes]
                : ['status' => FreelancerInvoiceStatus::Disputed, 'notes' => $note]);
        });

        $this->log($user, $invoice, FreelancerInvoiceStatus::Received, $approve ? 'approved' : 'disputed', ($approve ? 'Approved' : 'Disputed')." invoice {$invoice->invoice_number} from ".$this->workspace->personName($invoice->person_id).($note ? ": {$note}" : ''));
        $this->workspace->notify($invoice->person_id, 'invoice', $approve ? "Invoice {$invoice->invoice_number} approved" : "Invoice {$invoice->invoice_number} queried", $approve ? 'It is in the next payment run.' : (string) $note, 'my-work');
    }

    public function markPaid(User $user, int $invoiceId, string $paidOn): void
    {
        abort_unless($user->can('freelancer_invoices.approve'), 403);
        Validator::make(['paid_on' => $paidOn], ['paid_on' => ['required', 'date']])->validate();

        $invoice = FreelancerInvoice::query()->where('status', FreelancerInvoiceStatus::Approved)->findOrFail($invoiceId);
        $invoice->update(['status' => FreelancerInvoiceStatus::Paid, 'paid_on' => $paidOn]);
        $this->log($user, $invoice, FreelancerInvoiceStatus::Approved, 'paid', "Recorded invoice {$invoice->invoice_number} as paid");
    }

    private function create(User $user, int $personId, string $start, string $end, string $number, Money $net, Money $vat, bool $selfBilled, ?UploadedFile $file): FreelancerInvoice
    {
        if (FreelancerInvoice::query()->where('person_id', $personId)->where('invoice_number', $number)->exists()) {
            throw ValidationException::withMessages(['invoice_number' => 'You have already sent an invoice with that number.']);
        }

        $overlap = FreelancerInvoice::query()->where('person_id', $personId)->where('status', '!=', FreelancerInvoiceStatus::Disputed)
            ->whereDate('period_start', '<=', $end)->whereDate('period_end', '>=', $start)->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['period_start' => 'Part of that period is already invoiced.']);
        }

        $expected = $this->workspace->approvedTimeCost($personId, $start, $end);

        $invoice = DB::transaction(function () use ($personId, $start, $end, $number, $net, $vat, $selfBilled, $file, $expected) {
            $invoice = FreelancerInvoice::query()->create([
                'person_id' => $personId, 'period_start' => $start, 'period_end' => $end, 'invoice_number' => $number,
                'net_minor' => $net->minor, 'vat_minor' => $vat->minor, 'gross_minor' => $net->plus($vat)->minor, 'expected_minor' => $expected->minor,
                'status' => FreelancerInvoiceStatus::Received, 'is_self_billed' => $selfBilled,
                'file_path' => $file?->store("documents/freelancer-invoices/{$personId}", config('filesystems.documents_disk', 'local')),
                'original_name' => $file?->getClientOriginalName(),
            ]);

            $this->approvals->request($invoice);

            return $invoice;
        });

        $difference = $net->minus($expected);

        activity('finance')
            ->causedBy($user)
            ->performedOn($invoice)
            ->withProperties(['ref' => $number, 'attributes' => ['net' => $net->format(), 'expected' => $expected->format(), 'difference' => $difference->format(), 'self_billed' => $selfBilled]])
            ->event($selfBilled ? 'self-billed' : 'uploaded')
            ->log(($selfBilled ? 'Raised self-billed invoice ' : 'Received invoice ')."{$number} from ".$this->workspace->personName($personId).($difference->isZero() ? ', matching approved time' : ", {$difference->format()} against approved time"));

        return $invoice;
    }

    private function log(User $user, FreelancerInvoice $invoice, FreelancerInvoiceStatus $old, string $event, string $message): void
    {
        activity('finance')
            ->causedBy($user)
            ->performedOn($invoice)
            ->withProperties(['ref' => $invoice->invoice_number, 'old' => ['status' => $old->value], 'attributes' => ['status' => $invoice->status->value]])
            ->event($event)
            ->log($message);
    }
}
