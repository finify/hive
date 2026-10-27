<?php

namespace App\Services\AskHive\Operations;

use App\Actions\Resourcing\SetUnavailability as Action;
use App\Models\User;
use App\Support\DateRange;

class MarkUnavailable extends Support
{
    public static function name(): string
    {
        return 'mark_unavailable';
    }

    public function description(): string
    {
        return 'Mark dates a freelancer can’t work, so producers see it before booking.';
    }

    public function schema(): array
    {
        return self::object(['start_date' => ['type' => 'string'], 'end_date' => ['type' => 'string'], 'note' => ['type' => 'string']], ['start_date', 'end_date']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('crew.respond') && $this->workspace->isFreelancer($user->person_id);
    }

    public function summary(User $user, array $input): string
    {
        return 'Mark yourself unavailable, '.DateRange::of((string) $input['start_date'], (string) $input['end_date'])->format();
    }

    public function perform(User $user, array $input): string
    {
        app(Action::class)->add($user, ['start_date' => $this->str($input, 'start_date'), 'end_date' => $this->str($input, 'end_date'), 'note' => $this->str($input, 'note')]);

        return 'Marked unavailable. Producers will see it on the rota.';
    }
}
