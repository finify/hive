<?php

namespace App\Services\AskHive\Operations;

use App\Actions\Time\AddTimesheetEntry as Action;
use App\Models\User;
use App\Support\Display;

class AddTimesheetEntry extends Support
{
    public static function name(): string
    {
        return 'add_timesheet_entry';
    }

    public function description(): string
    {
        return 'Add time to the user’s own timesheet: on an event they are booked on (event_code) or an internal code (Office, Pitch, Training, Travel). Times are 24-hour HH:MM.';
    }

    public function schema(): array
    {
        return self::object([
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            'event_code' => ['type' => 'string', 'description' => 'For event time, for example EV-26114'],
            'internal_code' => ['type' => 'string', 'enum' => Action::INTERNAL_CODES],
            'start_time' => ['type' => 'string', 'description' => 'HH:MM'],
            'end_time' => ['type' => 'string', 'description' => 'HH:MM'],
            'break_minutes' => ['type' => 'integer'],
            'notes' => ['type' => 'string'],
        ], ['date', 'start_time', 'end_time', 'break_minutes']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('timesheets.submit') && $user->person_id !== null;
    }

    public function summary(User $user, array $input): string
    {
        $what = $this->str($input, 'event_code') ? strtoupper((string) $this->str($input, 'event_code')) : ($this->str($input, 'internal_code') ?? 'time');

        return sprintf('Add %s–%s (%d min break) on %s, %s', $input['start_time'], $input['end_time'], (int) ($input['break_minutes'] ?? 0), $what, Display::date((string) $input['date'], true));
    }

    public function perform(User $user, array $input): string
    {
        $work = $this->str($input, 'event_code') !== null ? 'event:'.$this->event($this->str($input, 'event_code'))['id'] : (string) $this->str($input, 'internal_code');

        $entry = app(Action::class)->handle($user, [
            'date' => $this->str($input, 'date'), 'work' => $work, 'start_time' => $this->str($input, 'start_time'),
            'end_time' => $this->str($input, 'end_time'), 'break_minutes' => $this->int($input, 'break_minutes') ?? 0, 'notes' => $this->str($input, 'notes'),
        ]);

        return 'Added '.Display::hours((float) $entry['hours']).' to your timesheet. Submit the week when it’s complete.';
    }
}
