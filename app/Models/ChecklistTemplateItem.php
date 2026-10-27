<?php

namespace App\Models;

use App\Enums\ChecklistOwner;
use Illuminate\Database\Eloquent\Model;

/**
 * One item on a checklist template, due a number of days from the start
 * (onboarding) or leaving date (offboarding); negative is before it.
 *
 * @property int $id
 * @property int $checklist_template_id
 * @property string $title
 * @property ChecklistOwner $owner
 * @property int $due_offset_days
 * @property int $position
 */
class ChecklistTemplateItem extends Model
{
    protected $fillable = ['checklist_template_id', 'title', 'owner', 'due_offset_days', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['owner' => ChecklistOwner::class, 'due_offset_days' => 'integer', 'position' => 'integer'];
    }
}
