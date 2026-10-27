<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\SupplierCategory;
use App\Models\Concerns\TouchesWorkspace;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $name
 * @property SupplierCategory $category
 * @property string|null $vat_number
 * @property int $payment_terms_days
 * @property bool $is_approved
 * @property string|null $rating
 * @property string|null $notes
 * @property-read Collection<int, Contact> $contacts
 * @property-read Collection<int, Document> $documents
 */
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory, LogsActivity, SoftDeletes, TouchesWorkspace;

    protected $fillable = ['name', 'category', 'vat_number', 'payment_terms_days', 'is_approved', 'rating', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['category' => SupplierCategory::class, 'is_approved' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('directory')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * @return MorphMany<Contact, $this>
     */
    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable')->orderByDesc('is_primary')->orderBy('name');
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        $insurance = $this->documents
            ->filter(fn (Document $d) => $d->type === DocumentType::PublicLiabilityInsurance && $d->expires_at !== null)
            ->sortByDesc('expires_at')
            ->first();

        return [
            'id' => $this->id, 'name' => $this->name, 'category' => $this->category->value, 'vat_number' => $this->vat_number,
            'payment_terms_days' => $this->payment_terms_days, 'is_approved' => $this->is_approved,
            'rating' => $this->rating === null ? null : (float) $this->rating,
            'insurance_expires_at' => $insurance?->expires_at?->toDateString(),
            'notes' => $this->notes,
            'contacts' => $this->contacts->map(fn (Contact $c) => $c->toWorkspaceArray())->values()->all(),
        ];
    }
}
