<?php

namespace App\Actions\Resourcing;

use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Enums\PhaseType;
use App\Models\CrewCall;
use App\Models\Event;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adds, changes or removes a crew call on an event: a role, how many people,
 * and the dates and hours they're needed.
 */
class SaveCrewCall
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, Event $event, array $input, ?CrewCall $call = null): CrewCall
    {
        Gate::forUser($user)->authorize('event.manage-crew', [$this->workspace->event($event->id) ?? []]);
        abort_if($call !== null && $call->event_id !== $event->id, 404);

        $data = Validator::make($input, [
            'role_name' => ['required', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'skill_id' => ['nullable', 'integer', 'exists:skills,id'],
            'min_skill_level' => ['nullable', 'integer', 'between:1,5', 'required_with:skill_id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'phase' => ['nullable', Rule::enum(PhaseType::class)],
            'call_time' => ['required', 'date_format:H:i'],
            'wrap_time' => ['required', 'date_format:H:i', 'different:call_time'],
            'location' => ['nullable', 'string', 'max:255'],
            'budgeted_rate' => ['nullable', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'budgeted_rate.regex' => 'Enter the day rate in pounds, for example 380 or 412.50.',
            'wrap_time.different' => 'The wrap time must differ from the call time.',
            'min_skill_level.required_with' => 'Choose the minimum level for that skill.',
        ])->validate();

        if ($call !== null) {
            $booked = $call->assignments()->whereIn('status', [AssignmentStatus::Confirmed, AssignmentStatus::FirstHold])->count();

            if ((int) $data['quantity'] < $booked) {
                throw ValidationException::withMessages(['quantity' => "{$booked} people are already booked. Release someone before reducing the number."]);
            }
        }

        $creating = $call === null;
        $call ??= new CrewCall(['event_id' => $event->id]);
        $old = $creating ? [] : $this->snapshot($call);

        $call->fill([
            'role_name' => $data['role_name'],
            'quantity' => (int) $data['quantity'],
            'skill_id' => $data['skill_id'] ?? null,
            'min_skill_level' => isset($data['skill_id']) ? ($data['min_skill_level'] ?? null) : null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'phase' => $data['phase'] ?? null,
            'call_time' => $data['call_time'],
            'wrap_time' => $data['wrap_time'],
            'location' => $data['location'] ?? null,
            'budgeted_rate_minor' => filled($data['budgeted_rate'] ?? null) ? Money::parse((string) $data['budgeted_rate'])->minor : null,
            'currency' => 'GBP',
            'notes' => $data['notes'] ?? null,
        ])->save();

        $new = $this->snapshot($call);

        activity('resourcing')
            ->causedBy($user)
            ->performedOn($call)
            ->withProperties(['ref' => $event->code, 'old' => array_diff_assoc($old, $new), 'attributes' => $creating ? $new : array_diff_assoc($new, $old)])
            ->event($creating ? 'crew-call-added' : 'crew-call-updated')
            ->log(($creating ? 'Added a crew call for ' : 'Updated the crew call for ')."{$call->quantity} × {$call->role_name} on {$event->name}");

        $this->workspace->forget();

        return $call;
    }

    /**
     * A crew call can go only while nobody is booked on it.
     */
    public function delete(User $user, CrewCall $call): void
    {
        Gate::forUser($user)->authorize('event.manage-crew', [$this->workspace->event($call->event_id) ?? []]);

        $live = $call->assignments()->get()->filter(fn ($a) => $a->status->holdsPerson())->count();

        if ($live > 0) {
            throw ValidationException::withMessages(['call' => 'Release everyone booked on this crew call first.']);
        }

        $event = $this->workspace->event($call->event_id);
        $before = $this->snapshot($call);
        $call->delete();

        activity('resourcing')
            ->causedBy($user)
            ->withProperties(['ref' => $event['code'] ?? null, 'old' => $before])
            ->event('crew-call-removed')
            ->log("Removed the crew call for {$call->quantity} × {$call->role_name}");

        $this->workspace->forget();
    }

    /**
     * @return array<string, scalar|null>
     */
    private function snapshot(CrewCall $call): array
    {
        return [
            'role_name' => $call->role_name, 'quantity' => $call->quantity, 'skill_id' => $call->skill_id, 'min_skill_level' => $call->min_skill_level,
            'start_date' => $call->start_date?->toDateString(), 'end_date' => $call->end_date?->toDateString(), 'phase' => $call->phase,
            'call_time' => $call->call_time, 'wrap_time' => $call->wrap_time, 'location' => $call->location,
            'budgeted_rate' => $call->budgeted_rate_minor === null ? null : Money::of($call->budgeted_rate_minor)->format(),
        ];
    }
}
