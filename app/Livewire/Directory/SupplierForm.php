<?php

namespace App\Livewire\Directory;

use App\Actions\Directory\SaveSupplier;
use App\Enums\DocumentType;
use App\Enums\SupplierCategory;
use App\Livewire\Directory\Concerns\EditsContacts;
use App\Models\Supplier;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SupplierForm extends Component
{
    use EditsContacts;

    public ?int $supplierId = null;

    /** @var array<string, mixed> */
    public array $form = ['name' => '', 'category' => 'av-hire', 'vat_number' => '', 'payment_terms_days' => 30, 'is_approved' => false, 'rating' => '', 'insurance_expires_at' => '', 'notes' => ''];

    public function mount(?Supplier $supplier = null): void
    {
        if ($supplier?->exists) {
            $this->authorize('update', $supplier);
            $this->supplierId = $supplier->id;
            $this->form = $supplier->only(['name', 'vat_number', 'payment_terms_days', 'is_approved', 'rating', 'notes']) + [
                'category' => $supplier->category->value,
                'insurance_expires_at' => $supplier->documents()->where('type', DocumentType::PublicLiabilityInsurance)->latest('id')->first()?->expires_at?->toDateString() ?? '',
            ];
            $this->loadContacts($supplier->contacts()->get());

            return;
        }

        $this->authorize('create', Supplier::class);
        $this->addContact();
    }

    public function save(SaveSupplier $save): void
    {
        $this->resetValidation();
        $input = $this->form + ['contacts' => $this->contacts];

        foreach (['rating', 'insurance_expires_at'] as $nullable) {
            $input[$nullable] = $input[$nullable] === '' ? null : $input[$nullable];
        }

        try {
            $supplier = $save->handle(auth()->user(), $input, $this->supplierId ? Supplier::query()->findOrFail($this->supplierId) : null);
        } catch (ValidationException $e) {
            $this->setErrorBag($this->prefixErrors($e->errors()));

            return;
        }

        session()->flash('toast', ['message' => "Saved {$supplier->name}."]);
        $this->redirectRoute('suppliers', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.directory.supplier-form', ['categories' => SupplierCategory::options()])
            ->title($this->supplierId ? 'Edit supplier' : 'Add a supplier');
    }
}
