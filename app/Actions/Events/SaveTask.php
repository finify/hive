<?php

namespace App\Actions\Events;

use App\Demo\Workspace;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Adds a task to an event, and moves a task's status on.
 */
class SaveTask
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function add(User $user, Event $event, array $input): Task
    {
        Gate::forUser($user)->authorize('event.edit', [$this->workspace->event($event->id) ?? []]);

        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:150'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('people', 'id')->withoutTrashed()],
            'due_date' => ['nullable', 'date'],
        ], ['title.required' => 'Say what needs doing.'])->validate();

        $task = $event->tasks()->create([
            'title' => $data['title'],
            'assignee_id' => $data['assignee_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'status' => TaskStatus::ToDo,
            'sort_order' => (int) $event->tasks()->max('sort_order') + 1,
        ]);

        activity('events')
            ->causedBy($user)
            ->performedOn($task)
            ->withProperties(['ref' => $event->code, 'attributes' => ['title' => $task->title, 'assignee_id' => $task->assignee_id, 'due_date' => $data['due_date'] ?? null]])
            ->event('task-added')
            ->log("Added the task “{$task->title}” to {$event->name}");

        $this->workspace->forget();

        return $task;
    }

    public function setStatus(User $user, Task $task, TaskStatus $status): Task
    {
        $event = $this->workspace->event($task->event_id);
        Gate::forUser($user)->authorize('event.update-task', [$event ?? [], $task->assignee_id]);

        $old = $task->status;

        if ($old === $status) {
            return $task;
        }

        $task->update(['status' => $status]);

        activity('events')
            ->causedBy($user)
            ->performedOn($task)
            ->withProperties(['ref' => $event['code'] ?? null, 'old' => ['status' => $old->value], 'attributes' => ['status' => $status->value]])
            ->event('task-updated')
            ->log("Marked “{$task->title}” as {$status->label()}");

        $this->workspace->forget();

        return $task;
    }
}
