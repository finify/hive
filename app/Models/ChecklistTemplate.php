<?php

namespace App\Models;

use App\Enums\ChecklistKind;
use App\Enums\EmploymentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The onboarding or offboarding items for one employment type.
 *
 * @property int $id
 * @property ChecklistKind $kind
 * @property EmploymentType $employment_type
 * @property string $name
 * @property-read Collection<int, ChecklistTemplateItem> $items
 */
class ChecklistTemplate extends Model
{
    protected $fillable = ['kind', 'employment_type', 'name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => ChecklistKind::class, 'employment_type' => EmploymentType::class];
    }

    /**
     * @return HasMany<ChecklistTemplateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class)->orderBy('position');
    }
}
