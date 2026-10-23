<?php

namespace App\Livewire;

use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Notifications')]
class Notifications extends Component
{
    use UsesWorkspace;

    public function markAllRead(): void
    {
        foreach ($this->workspace()->notificationsFor(auth()->user()->person_id) as $n) {
            $this->workspace()->ledger->put('notifications_read', $n['key'], true);
        }

        $this->workspace()->forget();
        $this->redirectRoute('notifications', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.notifications', [
            'notifications' => $this->workspace()->notificationsFor(auth()->user()->person_id),
        ]);
    }
}
