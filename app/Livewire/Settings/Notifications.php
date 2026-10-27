<?php

namespace App\Livewire\Settings;

use App\Actions\Calendar\CalendarFeeds;
use App\Actions\Settings\SaveNotificationPreferences;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\Person;
use App\Support\NotificationPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Notifications and calendar')]
class Notifications extends Component
{
    use UsesWorkspace;

    /** @var array<string, bool> */
    public array $email = [];

    public bool $digest = false;

    /** Shown once, straight after a new feed address is issued. */
    public ?string $feedUrl = null;

    public function mount(): void
    {
        $person = auth()->user()->person_id ? Person::query()->find(auth()->user()->person_id) : null;
        $prefs = NotificationPreferences::of($person ?? auth()->user());
        $this->email = $prefs->email;
        $this->digest = $prefs->digest;
    }

    public function save(SaveNotificationPreferences $save): void
    {
        try {
            $save->handle(auth()->user(), $this->email, $this->digest);
        } catch (ValidationException $e) {
            $this->toast((string) collect($e->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast('Notification settings saved.');
    }

    public function issueFeed(CalendarFeeds $feeds): void
    {
        $this->feedUrl = route('calendar.personal', $feeds->issue(auth()->user()));
        $this->toast('New calendar address made. Any old one has stopped working.');
    }

    public function revokeFeed(CalendarFeeds $feeds): void
    {
        $feeds->revoke(auth()->user());
        $this->feedUrl = null;
        $this->toast('Calendar feed turned off.');
    }

    public function render(): View
    {
        return view('livewire.settings.notifications', [
            'types' => NotificationPreferences::TYPES,
            'linked' => auth()->user()->person_id !== null,
            'hasFeed' => auth()->user()->calendar_token_hash !== null,
        ]);
    }
}
