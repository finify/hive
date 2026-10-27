<?php

namespace App\Services\AskHive\Operations;

use App\Actions\Events\SaveTask;
use App\Models\Event;
use App\Models\User;
use App\Support\Display;

class AddTask extends Support
{
    public static function name(): string
    {
        return 'add_task';
    }

    public function description(): string
    {
        return 'Add a task to an event, optionally with an owner (person id from search_people) and a due date.';
    }

    public function schema(): array
    {
        return self::object([
            'event_code' => ['type' => 'string'], 'title' => ['type' => 'string'],
            'assignee_id' => ['type' => 'integer'], 'due_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
        ], ['event_code', 'title']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('events.edit');
    }

    public function summary(User $user, array $input): string
    {
        $event = $this->event($this->str($input, 'event_code'));
        $who = $this->int($input, 'assignee_id') ? ' for '.$this->workspace->personName($this->int($input, 'assignee_id')) : '';
        $due = $this->str($input, 'due_date') ? ', due '.Display::date((string) $this->str($input, 'due_date')) : '';

        return "Add “{$this->str($input, 'title')}” to {$event['code']}{$who}{$due}";
    }

    public function perform(User $user, array $input): string
    {
        $event = Event::query()->findOrFail($this->event($this->str($input, 'event_code'))['id']);
        app(SaveTask::class)->add($user, $event, ['title' => $this->str($input, 'title'), 'assignee_id' => $this->int($input, 'assignee_id'), 'due_date' => $this->str($input, 'due_date')]);

        return 'Task added.';
    }
}
