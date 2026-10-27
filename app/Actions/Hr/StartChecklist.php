<?php

namespace App\Actions\Hr;

use App\Demo\Workspace;
use App\Enums\ChecklistKind;
use App\Enums\ChecklistOwner;
use App\Models\Checklist;
use App\Models\ChecklistTemplate;
use App\Models\Person;
use App\Models\User;
use App\Support\Display;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Starts a person's onboarding (from their start date) or offboarding (from
 * their leaving date), copying the template for their employment type. One
 * open checklist of each kind per person.
 */
class StartChecklist
{
    public function __construct(private readonly Workspace $workspace) {}

    public function handle(?User $user, Person $person, ChecklistKind $kind, bool $quiet = false): Checklist
    {
        if ($user !== null) {
            Gate::forUser($user)->authorize('people.manage');
        }

        $anchor = $kind === ChecklistKind::Onboarding ? $person->start_date : $person->end_date;

        if ($anchor === null) {
            throw ValidationException::withMessages(['checklist' => "Add {$person->first_name}’s leaving date first."]);
        }

        if (Checklist::query()->where('person_id', $person->id)->where('kind', $kind)->whereNull('completed_at')->exists()) {
            throw ValidationException::withMessages(['checklist' => "{$person->first_name} already has an open ".mb_strtolower($kind->label()).' checklist.']);
        }

        $template = ChecklistTemplate::query()->with('items')->where('kind', $kind)->where('employment_type', $person->employment_type)->first();

        if ($template === null) {
            throw ValidationException::withMessages(['checklist' => 'There is no '.mb_strtolower($kind->label())." template for {$person->employment_type->label()} yet."]);
        }

        $checklist = DB::transaction(function () use ($person, $kind, $anchor, $template, $user) {
            $checklist = Checklist::query()->create([
                'person_id' => $person->id, 'kind' => $kind, 'anchor_date' => $anchor, 'started_by' => $user?->person_id,
            ]);

            foreach ($template->items as $item) {
                $checklist->items()->create([
                    'title' => $item->title, 'owner' => $item->owner, 'position' => $item->position,
                    'due_date' => $anchor->copy()->addDays($item->due_offset_days),
                ]);
            }

            return $checklist;
        });

        if ($quiet) {
            return $checklist;
        }

        $name = $this->workspace->personName($person->id);

        activity('people')
            ->causedBy($user)
            ->performedOn($checklist)
            ->withProperties(['ref' => 'CL-'.$checklist->id, 'attributes' => ['kind' => $kind->value, 'anchor_date' => $anchor->toDateString(), 'items' => $template->items->count()]])
            ->event('checklist-started')
            ->log("Started {$name}’s ".mb_strtolower($kind->label()));

        if ($person->line_manager_id && $template->items->contains(fn ($i) => $i->owner === ChecklistOwner::LineManager)) {
            $this->workspace->notify($person->line_manager_id, 'people', "{$name}’s ".mb_strtolower($kind->label()).' has started',
                'Some items are yours, the first due '.Display::date($anchor->copy()->addDays((int) $template->items->where('owner', ChecklistOwner::LineManager)->min('due_offset_days'))->toDateString()).'.', 'onboarding');
        }

        return $checklist;
    }
}
