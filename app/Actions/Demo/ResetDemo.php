<?php

namespace App\Actions\Demo;

use App\Demo\Workspace;
use App\Models\User;

class ResetDemo
{
    public function __construct(private readonly Workspace $workspace) {}

    public function handle(User $user): void
    {
        abort_unless(config('hive.demo.enabled'), 404);

        $changes = $this->workspace->ledger->count();
        $this->workspace->ledger->reset();
        $this->workspace->forget();

        activity('demo')->causedBy($user)->withProperties(['changes_cleared' => $changes])->event('reset')->log('Reset the demo walkthrough');
    }
}
