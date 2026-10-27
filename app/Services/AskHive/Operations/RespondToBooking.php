<?php

namespace App\Services\AskHive\Operations;

use App\Actions\Resourcing\RespondToBookingRequest as Action;
use App\Demo\Insights;
use App\Demo\Viewer;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Validation\ValidationException;

class RespondToBooking extends Support
{
    public static function name(): string
    {
        return 'respond_to_booking';
    }

    public function description(): string
    {
        return 'Accept or decline one of the user’s own booking requests, by event code.';
    }

    public function schema(): array
    {
        return self::object(['event_code' => ['type' => 'string'], 'accept' => ['type' => 'boolean']], ['event_code', 'accept']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('crew.respond');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function request(User $user, array $input): array
    {
        $event = $this->event($this->str($input, 'event_code'));

        $viewer = new Viewer($user, $this->workspace);

        return (new Insights($this->workspace, $viewer))->myBookingRequests()->firstWhere('event_id', $event['id'])
            ?? throw ValidationException::withMessages(['event_code' => "You have no booking request waiting on {$event['code']}."]);
    }

    public function summary(User $user, array $input): string
    {
        $a = $this->request($user, $input);
        $event = $this->workspace->event($a['event_id']);

        return (! empty($input['accept']) ? 'Accept' : 'Decline')." the booking on {$event['code']} {$event['name']}, ".DateRange::of($a['start_date'], $a['end_date'])->format();
    }

    public function perform(User $user, array $input): string
    {
        $accept = (bool) ($input['accept'] ?? false);
        app(Action::class)->handle($user, $this->request($user, $input)['id'], $accept);

        return $accept ? 'Booking accepted. The producer has been told.' : 'Booking declined. The producer has been told.';
    }
}
