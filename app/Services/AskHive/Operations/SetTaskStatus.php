<?php

namespace App\Services\AskHive\Operations;

use App\Actions\Events\SaveTask;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SetTaskStatus extends Support
{
    public static function name(): string
    {
        return 'set_task_status';
    }

    public function description(): string
    {
        return 'Move an event task to to-do, in-progress or done (task id from get_event_tasks).';
    }

    public function schema(): array
    {
        return self::object(['task_id' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => ['to-do', 'in-progress', 'done']]], ['task_id', 'status']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('events.view');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function task(array $input): Task
    {
        return Task::query()->find((int) $this->int($input, 'task_id')) ?? throw ValidationException::withMessages(['task_id' => 'That task doesn’t exist.']);
    }

    public function summary(User $user, array $input): string
    {
        $task = $this->task($input);
        $status = TaskStatus::tryFrom((string) $this->str($input, 'status')) ?? throw ValidationException::withMessages(['status' => 'Choose to-do, in-progress or done.']);
        $event = $this->workspace->event($task->event_id);

        return "Mark “{$task->title}” on ".($event['code'] ?? 'the event').' as '.mb_strtolower($status->label());
    }

    public function perform(User $user, array $input): string
    {
        app(SaveTask::class)->setStatus($user, $this->task($input), TaskStatus::from((string) $this->str($input, 'status')));

        return 'Task updated.';
    }
}
