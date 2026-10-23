<?php

namespace App\Actions\Directory;

use App\Enums\DocumentType;
use App\Enums\SupplierCategory;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveSupplier
{
    public function __construct(private readonly SaveContacts $contacts) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input, ?Supplier $supplier = null): Supplier
    {
        $supplier === null
            ? Gate::forUser($user)->authorize('create', Supplier::class)
            : Gate::forUser($user)->authorize('update', $supplier);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150', Rule::unique('suppliers', 'name')->ignore($supplier?->id)],
            'category' => ['required', Rule::enum(SupplierCategory::class)],
            'vat_number' => ['nullable', 'string', 'max:20'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:120'],
            'is_approved' => ['boolean'],
            'rating' => ['nullable', 'numeric', 'between:1,5'],
            'insurance_expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ] + SaveContacts::rules())->validate();

        return DB::transaction(function () use ($supplier, $data) {
            $supplier ??= new Supplier;
            $supplier->fill(collect($data)->except(['contacts', 'insurance_expires_at'])->all())->save();
            $this->contacts->handle($supplier, $data['contacts'] ?? []);

            $insurance = $supplier->documents()->where('type', DocumentType::PublicLiabilityInsurance)->latest('id')->first();

            if (! empty($data['insurance_expires_at']) && $insurance?->expires_at?->toDateString() !== $data['insurance_expires_at']) {
                $supplier->documents()->create([
                    'type' => DocumentType::PublicLiabilityInsurance,
                    'title' => 'Public liability insurance',
                    'expires_at' => $data['insurance_expires_at'],
                    'mandatory' => true,
                ]);
            }

            return $supplier->refresh();
        });
    }
}
