<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Models\Concerns\TouchesWorkspace;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Everyone who works for the agency, staff and freelance.
 *
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $preferred_name
 * @property string|null $pronouns
 * @property string $email
 * @property string|null $phone
 * @property EmploymentType $employment_type
 * @property int|null $department_id
 * @property string $job_title
 * @property int|null $line_manager_id
 * @property string $location_city
 * @property string $location_country
 * @property string $bank_holiday_region
 * @property array{email?: array<string, bool>, digest?: bool}|null $notification_preferences
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property string|null $company_name
 * @property bool $vat_registered
 * @property string|null $vat_number
 * @property int|null $payment_terms_days
 * @property list<string>|null $languages
 * @property string|null $rating
 * @property string|null $notes
 * @property bool $is_active
 * @property-read PersonRestricted|null $restricted
 * @property-read Collection<int, DayRate> $dayRates
 * @property-read Collection<int, Skill> $skills
 * @property-read Collection<int, Document> $documents
 */
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, LogsActivity, Notifiable, SoftDeletes, TouchesWorkspace;

    protected $table = 'people';

    protected $fillable = [
        'first_name', 'last_name', 'preferred_name', 'pronouns', 'email', 'phone', 'employment_type',
        'department_id', 'job_title', 'line_manager_id', 'location_city', 'location_country', 'bank_holiday_region', 'start_date',
        'end_date', 'company_name', 'vat_registered', 'vat_number', 'payment_terms_days', 'languages',
        'rating', 'notes', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'vat_registered' => 'boolean',
            'languages' => 'array',
            'notes' => 'encrypted',
            'is_active' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Restricted and confidential fields stay out of the audit trail.
        return LogOptions::defaults()
            ->useLogName('people')
            ->logOnly(['first_name', 'last_name', 'preferred_name', 'email', 'employment_type', 'department_id', 'job_title', 'line_manager_id', 'location_city', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function displayName(): string
    {
        return ($this->preferred_name ?: $this->first_name).' '.$this->last_name;
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function lineManager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'line_manager_id');
    }

    /**
     * @return HasOne<PersonRestricted, $this>
     */
    public function restricted(): HasOne
    {
        return $this->hasOne(PersonRestricted::class);
    }

    /**
     * @return HasMany<DayRate, $this>
     */
    public function dayRates(): HasMany
    {
        return $this->hasMany(DayRate::class)->orderBy('effective_from');
    }

    /**
     * @return BelongsToMany<Skill, $this>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)->withPivot(['level', 'verified_by', 'verified_at'])->withTimestamps();
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * @param  Builder<Person>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The shape the rest of Hive reads (see App\Demo\Workspace). Relations
     * must be eager loaded: restricted, dayRates, skills.
     *
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(bool $sensitive = true): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'preferred_name' => $this->preferred_name,
            'pronouns' => $this->pronouns,
            'email' => $this->email,
            'phone' => $this->phone,
            'employment_type' => $this->employment_type->value,
            'department_id' => $this->department_id,
            'job_title' => $this->job_title,
            'line_manager_id' => $this->line_manager_id,
            'location_city' => $this->location_city,
            'location_country' => $this->location_country,
            'bank_holiday_region' => $this->bank_holiday_region,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'company_name' => $this->company_name,
            'vat_registered' => $this->vat_registered,
            'vat_number' => $this->vat_number,
            'payment_terms_days' => $this->payment_terms_days,
            'is_active' => $this->is_active,
            'skills' => $this->skills->map(fn (Skill $skill) => [
                'skill_id' => $skill->id,
                'level' => (int) $skill->getRelationValue('pivot')->getAttribute('level'),
                'verified' => $skill->getRelationValue('pivot')->getAttribute('verified_at') !== null,
            ])->values()->all(),
            'languages' => $this->languages ?? [],
            'day_rates' => $this->dayRates->map(fn (DayRate $rate) => [
                'role_name' => $rate->role_name,
                'amount_minor' => $rate->amount_minor,
                'currency' => $rate->currency,
                'effective_from' => $rate->effective_from->toDateString(),
                'effective_to' => $rate->effective_to?->toDateString(),
            ])->values()->all(),
            'rating' => $this->rating === null ? null : (float) $this->rating,
            'restricted' => ! $sensitive ? null : [
                'emergency_contact' => $this->restricted?->emergency_contact,
                'passport_number' => $this->restricted?->passport_number,
                'passport_expiry' => $this->restricted?->passport_expiry?->toDateString(),
                'nationality' => $this->restricted?->nationality,
                'dietary_requirements' => $this->restricted?->dietary_requirements,
                'access_needs' => $this->restricted?->access_needs,
            ],
            'notes' => $sensitive ? $this->notes : null,
        ];
    }
}
