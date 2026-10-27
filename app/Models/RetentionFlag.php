<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A record past its retention period, waiting for someone to decide.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $category timesheet, expense, leaver, document, ai-request
 * @property string $label
 * @property Carbon $past_retention_on
 * @property string|null $decision keep, anonymised, deleted
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 */
class RetentionFlag extends Model
{
    protected $fillable = ['subject_type', 'subject_id', 'category', 'label', 'past_retention_on', 'decision', 'decided_by', 'decided_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['past_retention_on' => 'date', 'decided_at' => 'datetime'];
    }
}
