<?php

namespace App\Services\AskHive\Operations;

use App\Actions\Hr\TickChecklistItem as Action;
use App\Models\ChecklistItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TickChecklistItem extends Support
{
    public static function name(): string
    {
        return 'tick_checklist_item';
    }

    public function description(): string
    {
        return 'Tick an onboarding or offboarding item done, or untick it (id from list_my_checklist_items).';
    }

    public function schema(): array
    {
        return self::object(['item_id' => ['type' => 'integer'], 'done' => ['type' => 'boolean']], ['item_id', 'done']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->canAny(['people.manage', 'leave.approve', 'rates.edit', 'users.manage']);
    }

    public function summary(User $user, array $input): string
    {
        $item = ChecklistItem::query()->with('checklist')->find((int) $this->int($input, 'item_id')) ?? throw ValidationException::withMessages(['item_id' => 'That item doesn’t exist.']);

        return (($input['done'] ?? true) ? 'Tick' : 'Untick')." “{$item->title}” for ".$this->workspace->personName($item->checklist->person_id);
    }

    public function perform(User $user, array $input): string
    {
        app(Action::class)->handle($user, (int) $this->int($input, 'item_id'), (bool) ($input['done'] ?? true));

        return 'Checklist updated.';
    }
}
