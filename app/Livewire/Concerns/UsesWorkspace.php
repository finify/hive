<?php

namespace App\Livewire\Concerns;

use App\Demo\Insights;
use App\Demo\Viewer;
use App\Demo\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * Livewire components read the agency through the workspace and always scope
 * what they show through the viewer.
 */
trait UsesWorkspace
{
    protected function workspace(): Workspace
    {
        return app(Workspace::class);
    }

    protected function viewer(): Viewer
    {
        return app(Viewer::class);
    }

    protected function insights(): Insights
    {
        return app(Insights::class);
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', message: $message, type: $type);
    }

    /**
     * Run an Action and turn its validation errors into an error toast, for
     * one-click actions that have no form to show errors next to.
     */
    protected function attempt(callable $action, string $success): bool
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->toast(collect($e->errors())->flatten()->first() ?? 'That did not work.', 'error');

            return false;
        }

        $this->toast($success);

        return true;
    }
}
