<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $task_template_id
 * @property string $title
 * @property int $due_offset_days
 * @property string|null $assignee_role
 * @property int $sort_order
 */
class TaskTemplateItem extends Model
{
    protected $fillable = ['task_template_id', 'title', 'due_offset_days', 'assignee_role', 'sort_order'];
}
