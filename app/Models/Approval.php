<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One approver's decision on something submitted: a timesheet entry, leave,
 * and from phase 5 purchase orders, invoices and expenses.
 *
 * @property int $id
 * @property string $approvable_type
 * @property int $approvable_id
 * @property int|null $requested_by
 * @property int $approver_id
 * @property string $mode
 * @property int $step
 * @property ApprovalStatus $status
 * @property int|null $decided_by
 * @property string|null $comment
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
class Approval extends Model
{
    protected $fillable = ['approvable_type', 'approvable_id', 'requested_by', 'approver_id', 'mode', 'step', 'status', 'decided_by', 'comment', 'decided_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ApprovalStatus::class, 'step' => 'integer', 'decided_at' => 'datetime'];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }
}
