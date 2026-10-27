<?php

namespace App\Services\AskHive\Operations;

use App\Actions\Time\ReturnTimesheetEntry as Action;
use App\Models\User;
use App\Support\Display;

class ReturnTimesheetEntry extends Support
{
    public static function name(): string
    {
        return 'return_timesheet_entry';
    }

    public function description(): string
    {
        return 'Send a timesheet entry back to the person with a comment saying what to change.';
    }

    public function schema(): array
    {
        return self::object(['entry_id' => ['type' => 'integer'], 'comment' => ['type' => 'string']], ['entry_id', 'comment']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('timesheets.approve');
    }

    public function summary(User $user, array $input): string
    {
        $found = $this->workspace->findEntry((int) $this->int($input, 'entry_id'));
        $who = $found ? $this->workspace->personName($found[0]['person_id']).', '.Display::date($found[1]['date']) : 'the entry';

        return "Return {$who}: “".$this->str($input, 'comment').'”';
    }

    public function perform(User $user, array $input): string
    {
        app(Action::class)->handle($user, (int) $this->int($input, 'entry_id'), (string) $this->str($input, 'comment'));

        return 'Returned. They have been told what to change.';
    }
}
