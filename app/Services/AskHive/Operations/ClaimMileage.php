<?php

namespace App\Services\AskHive\Operations;

use App\Actions\Finance\ManageExpense;
use App\Models\User;
use App\Support\Display;
use App\Support\Money;

class ClaimMileage extends Support
{
    public static function name(): string
    {
        return 'claim_mileage';
    }

    public function description(): string
    {
        return 'Claim mileage for driving on business (no receipt needed). Other expenses need a receipt, so send people to Expenses for those.';
    }

    public function schema(): array
    {
        return self::object([
            'date' => ['type' => 'string'], 'miles' => ['type' => 'number'], 'description' => ['type' => 'string', 'description' => 'From and to, and why'],
            'event_code' => ['type' => 'string'],
        ], ['date', 'miles', 'description']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('expenses.submit');
    }

    public function summary(User $user, array $input): string
    {
        $miles = (float) ($input['miles'] ?? 0);
        $amount = Money::of((int) round($miles * (int) config('hive.finance.mileage_rate_minor')));

        return "Claim {$miles} miles ({$amount->format()}) on ".Display::date((string) $input['date']).': '.$this->str($input, 'description')
            .($this->str($input, 'event_code') ? ' for '.strtoupper((string) $this->str($input, 'event_code')) : '');
    }

    public function perform(User $user, array $input): string
    {
        $eventId = $this->str($input, 'event_code') ? $this->event($this->str($input, 'event_code'))['id'] : null;

        app(ManageExpense::class)->submit($user, [
            'event_id' => $eventId, 'category' => 'mileage', 'date' => $this->str($input, 'date'),
            'description' => $this->str($input, 'description'), 'miles' => $input['miles'] ?? null,
        ]);

        return 'Mileage claimed. It goes to your approver.';
    }
}
