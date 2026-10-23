<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $name
 * @property string $sector
 * @property string|null $billing_address
 * @property string|null $vat_number
 * @property int $payment_terms_days
 * @property int|null $account_lead_id
 * @property string|null $notes
 * @property-read Collection<int, Contact> $contacts
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = ['name', 'sector', 'billing_address', 'vat_number', 'payment_terms_days', 'account_lead_id', 'notes'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('directory')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function accountLead(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'account_lead_id');
    }

    /**
     * @return MorphMany<Contact, $this>
     */
    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable')->orderByDesc('is_primary')->orderBy('name');
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'sector' => $this->sector, 'billing_address' => $this->billing_address,
            'vat_number' => $this->vat_number, 'payment_terms_days' => $this->payment_terms_days, 'account_lead_id' => $this->account_lead_id,
            'notes' => $this->notes,
            'contacts' => $this->contacts->map(fn (Contact $c) => $c->toWorkspaceArray())->values()->all(),
        ];
    }
}
