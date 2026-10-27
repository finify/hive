<?php

namespace App\Actions\Hr;

use App\Demo\Workspace;
use App\Models\ChecklistItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Ticks an item done, or un-ticks it. HR can tick anything; otherwise only
 * the item's owner (IT, the line manager, finance). The checklist closes
 * when every item is done and reopens if one is un-ticked.
 */
class TickChecklistItem
{
    public function __construct(private readonly Workspace $workspace) {}

    public function handle(User $user, int $itemId, bool $done = true): ChecklistItem
    {
        $item = ChecklistItem::query()->with('checklist.person')->findOrFail($itemId);
        $checklist = $item->checklist;

        if (! $item->owner->canTick($user, $checklist->person)) {
            throw new AuthorizationException("This item is for {$item->owner->label()}.");
        }

        $old = $item->completed_at !== null;

        if ($old === $done) {
            return $item;
        }

        $item->update(['completed_at' => $done ? now() : null, 'completed_by' => $done ? $user->person_id : null]);
        $open = $checklist->items()->whereNull('completed_at')->exists();
        $checklist->update(['completed_at' => $open ? null : ($checklist->completed_at ?? now())]);

        activity('people')
            ->causedBy($user)
            ->performedOn($item)
            ->withProperties(['ref' => 'CL-'.$checklist->id, 'old' => ['done' => $old], 'attributes' => ['done' => $done, 'item' => $item->title]])
            ->event($done ? 'checklist-item-done' : 'checklist-item-reopened')
            ->log(($done ? 'Ticked' : 'Reopened')." “{$item->title}” for {$this->workspace->personName($checklist->person_id)}");

        return $item;
    }
}
