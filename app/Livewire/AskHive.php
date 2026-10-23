<?php

namespace App\Livewire;

use App\Actions\Ai\AskHive as AskHiveAction;
use App\Demo\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The Ask Hive panel. Answers are drafts to read, with the records they used;
 * nothing here approves, books, pays or deletes anything.
 */
class AskHive extends Component
{
    public string $question = '';

    /** @var list<array{q: string, a: array<string, mixed>}> */
    public array $conversation = [];

    public function ask(AskHiveAction $ask): void
    {
        $this->resetValidation();
        try {
            $answer = $ask->handle(auth()->user(), $this->question);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->resetValidation();
        $this->conversation[] = ['q' => $this->question, 'a' => $answer->toArray()];
        $this->question = '';
    }

    public function suggest(string $question, AskHiveAction $ask): void
    {
        $this->question = $question;
        $this->ask($ask);
    }

    public function clear(): void
    {
        $this->conversation = [];
    }

    public function render(): View
    {
        $suggestions = app(Workspace::class)->askHiveSuggestions();
        $role = auth()->user()->primaryRole()?->value;
        $key = match ($role) {
            'producer', 'head-of-department' => 'producer',
            'freelancer' => 'freelancer',
            'employee' => 'employee',
            'hr' => 'hr',
            default => 'default',
        };

        return view('livewire.ask-hive', ['suggestions' => $suggestions[$key] ?? $suggestions['default']]);
    }
}
