<?php

namespace App\Actions\Events;

use App\Demo\Workspace;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RecordSignedContract
{
    public function __construct(private readonly Workspace $workspace) {}

    public function handle(User $user, int $eventId): void
    {
        $event = $this->workspace->event($eventId);

        if (! $event) {
            throw ValidationException::withMessages(['contract' => 'That event no longer exists.']);
        }

        if (! $user->can('events.edit_all') && ! ($user->can('events.edit') && $event['producer_id'] === $user->person_id)) {
            abort(403);
        }

        $files = $event['files'];
        $files[] = ['name' => "{$event['code']} signed contract.pdf", 'type' => 'contract'];

        $this->workspace->ledger->put('events', $eventId, array_merge(
            $this->workspace->ledger->section('events')[$eventId] ?? [],
            ['contract_signed' => true, 'files' => $files],
        ));
        $this->workspace->forget();

        activity('events')
            ->causedBy($user)
            ->withProperties(['ref' => $event['code'], 'old' => ['contract_signed' => false], 'attributes' => ['contract_signed' => true]])
            ->event('contract-signed')
            ->log("Uploaded the signed contract for {$event['name']}");
    }
}
