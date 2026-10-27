<?php

namespace App\Services\AskHive\Operations;

use App\Actions\Time\SubmitTimesheet as Action;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Display;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SubmitTimesheet extends Support
{
    public static function name(): string
    {
        return 'submit_timesheet';
    }

    public function description(): string
    {
        return 'Submit the user’s timesheet for a week (any date in the week) for approval.';
    }

    public function schema(): array
    {
        return self::object(['week' => ['type' => 'string', 'description' => 'Any date in the week, YYYY-MM-DD']], ['week']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('timesheets.submit') && $user->person_id !== null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function sheet(User $user, array $input): Timesheet
    {
        $start = CarbonImmutable::parse((string) $this->str($input, 'week'))->startOfWeek()->toDateString();

        return Timesheet::query()->with('entries')->where('person_id', $user->person_id)->whereDate('week_start', $start)->first()
            ?? throw ValidationException::withMessages(['week' => 'You have no time recorded for the week of '.Display::date($start).'.']);
    }

    public function summary(User $user, array $input): string
    {
        $sheet = $this->sheet($user, $input);

        return 'Submit your timesheet for the week of '.Display::date($sheet->week_start).' ('.$sheet->entries->count().' entries, '.Display::hours((float) $sheet->entries->sum('hours')).')';
    }

    public function perform(User $user, array $input): string
    {
        app(Action::class)->handle($user, $this->sheet($user, $input)->id);

        return 'Timesheet submitted. Each entry has gone to its approver.';
    }
}
