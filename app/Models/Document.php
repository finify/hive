<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A contract, insurance, certificate or other record, for a person or a
 * supplier. Files are stored privately and served through signed links.
 *
 * @property int $id
 * @property string $documentable_type
 * @property int $documentable_id
 * @property DocumentType $type
 * @property string $title
 * @property string|null $file_path
 * @property string|null $original_name
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property bool $mandatory
 * @property int|null $uploaded_by
 * @property int|null $reminded_days
 */
class Document extends Model
{
    use LogsActivity;

    protected $fillable = ['documentable_type', 'documentable_id', 'type', 'title', 'file_path', 'original_name', 'issued_at', 'expires_at', 'mandatory', 'uploaded_by', 'reminded_days'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'issued_at' => 'date',
            'expires_at' => 'date',
            'mandatory' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('people')->logOnly(['type', 'title', 'issued_at', 'expires_at', 'mandatory'])->logOnlyDirty();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function statusOn(CarbonImmutable $today): DocumentStatus
    {
        if ($this->expires_at === null) {
            return DocumentStatus::Valid;
        }

        if ($this->expires_at->lessThan($today)) {
            return DocumentStatus::Expired;
        }

        return $this->expires_at->lessThanOrEqualTo($today->addDays(30)) ? DocumentStatus::Expiring : DocumentStatus::Valid;
    }
}
