<?php

namespace App\Actions\Resourcing;

use App\Demo\Workspace;
use App\Models\Unavailability;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Facades\Validator;

/**
 * Freelancers mark the dates they can't work, so producers don't book them.
 * Employees use leave instead.
 */
class SetUnavailability
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array{start_date?: mixed, end_date?: mixed, note?: mixed}  $input
     */
    public function add(User $user, array $input): Unavailability
    {
        abort_unless($user->can('crew.respond') && $this->workspace->isFreelancer($user->person_id), 403);

        $data = Validator::make($input, [
            'start_date' => ['required', 'date', 'after_or_equal:'.$this->workspace->today()->toDateString()],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['start_date.after_or_equal' => 'Choose today or a later date.'])->validate();

        $row = Unavailability::query()->create(['person_id' => $user->person_id, 'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'note' => $data['note'] ?? null]);
        $dates = DateRange::of($data['start_date'], $data['end_date'])->format();

        activity('resourcing')
            ->causedBy($user)
            ->performedOn($row)
            ->withProperties(['ref' => 'P-'.$user->person_id, 'attributes' => ['start_date' => $data['start_date'], 'end_date' => $data['end_date']]])
            ->event('unavailable-added')
            ->log("Marked unavailable {$dates}");

        $this->workspace->forget();

        return $row;
    }

    public function remove(User $user, int $id): void
    {
        $row = Unavailability::query()->whereKey($id)->where('person_id', $user->person_id)->firstOrFail();
        $dates = DateRange::of($row->start_date->toDateString(), $row->end_date->toDateString())->format();
        $row->delete();

        activity('resourcing')
            ->causedBy($user)
            ->withProperties(['ref' => 'P-'.$user->person_id, 'old' => ['start_date' => $row->start_date->toDateString(), 'end_date' => $row->end_date->toDateString()]])
            ->event('unavailable-removed')
            ->log("Removed unavailability {$dates}");

        $this->workspace->forget();
    }
}
