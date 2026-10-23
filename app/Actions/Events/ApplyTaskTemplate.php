<?php

namespace App\Actions\Events;

use App\Demo\Workspace;
use App\Models\Event;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Adds the standard task list for the event's type. Due dates count from the
 * event's start; each task goes to the team member holding the template
 * item's role, or the producer.
 */
class ApplyTaskTemplate
{
    public function __construct(private readonly Workspace $workspace) {}

    public function handle(User $user, Event $event): int
    {
        Gate::forUser($user)->authorize('event.edit', [$this->workspace->event($event->id) ?? []]);

        if ($event->tasks()->exists()) {
            throw ValidationException::withMessages(['template' => 'This event already has tasks. The standard list is only added to an empty one.']);
        }

        $count = $this->apply($event);

        if ($count === 0) {
            throw ValidationException::withMessages(['template' => "There is no standard task list for {$event->event_type->label()} events yet."]);
        }

        activity('events')
            ->causedBy($user)
            ->performedOn($event)
            ->withProperties(['ref' => $event->code, 'attributes' => ['tasks_added' => $count]])
            ->event('tasks-added')
            ->log("Added the standard task list to {$event->name}");

        $this->workspace->forget();

        return $count;
    }

    /**
     * Creates the tasks without authorising or logging; the caller does both.
     */
    public function apply(Event $event): int
    {
        $template = TaskTemplate::query()->with('items')->where('event_type', $event->event_type)->first();

        if ($template === null) {
            return 0;
        }

        $roles = $event->team()->pluck('person_id', 'role');

        foreach ($template->items as $item) {
            /** @var TaskTemplateItem $item */
            Task::query()->create([
                'event_id' => $event->id,
                'title' => $item->title,
                'assignee_id' => $item->assignee_role === 'Producer' ? $event->producer_id : ($roles[$item->assignee_role] ?? $event->producer_id),
                'due_date' => $event->start_date?->copy()->addDays($item->due_offset_days)->toDateString(),
                'status' => 'to-do',
                'sort_order' => $item->sort_order,
            ]);
        }

        return $template->items->count();
    }
}
