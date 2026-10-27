<?php

namespace App\Livewire;

use App\Actions\Ai\AskHive as AskHiveAction;
use App\Actions\Ai\ConfirmProposal;
use App\Demo\Workspace;
use App\Models\AiRequest;
use App\Services\AskHive\Workflow;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Ask Hive panel. Questions are queued and the panel polls until each
 * one is answered; each follows on from the one before, so the conversation
 * carries context. Answers are drafts to read, with the records they used.
 * A change Ask Hive proposes happens only when the user confirms it here.
 */
class AskHive extends Component
{
    public string $question = '';

    /** @var list<int> Ask Hive requests in this conversation, oldest first. */
    public array $requestIds = [];

    public function ask(AskHiveAction $ask): void
    {
        $this->resetValidation();

        try {
            $request = $ask->handle(auth()->user(), $this->question, previousId: $this->previousId());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->requestIds[] = $request->id;
        $this->question = '';
    }

    public function suggest(string $question, AskHiveAction $ask): void
    {
        $this->question = $question;
        $this->ask($ask);
    }

    /**
     * Started by a button elsewhere: "Explain budget risk", "Draft wrap report"...
     */
    #[On('ask-hive-workflow')]
    public function workflow(string $workflow, ?string $subject, AskHiveAction $ask): void
    {
        $this->resetValidation();

        try {
            $request = $ask->handle(auth()->user(), '', Workflow::from($workflow), $subject, $this->previousId());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->requestIds[] = $request->id;
    }

    public function confirm(int $requestId, string $proposalId, ConfirmProposal $proposals): void
    {
        try {
            $result = $proposals->handle(auth()->user(), $requestId, $proposalId);
        } catch (ValidationException $e) {
            $this->dispatch('toast', message: $e->validator->errors()->first(), type: 'error');

            return;
        }

        $this->dispatch('toast', message: $result['message'], type: $result['ok'] ? 'success' : 'error');

        if ($result['ok']) {
            // The page behind the panel may show what just changed.
            $this->dispatch('ask-hive-changed');
        }
    }

    public function cancelProposal(int $requestId, string $proposalId, ConfirmProposal $proposals): void
    {
        try {
            $proposals->cancel(auth()->user(), $requestId, $proposalId);
        } catch (ValidationException $e) {
            $this->dispatch('toast', message: $e->validator->errors()->first(), type: 'error');
        }
    }

    private function previousId(): ?int
    {
        return $this->requestIds === [] ? null : (int) end($this->requestIds);
    }

    public function clear(): void
    {
        $this->requestIds = [];
    }

    public function render(): View
    {
        $requests = $this->requestIds === [] ? collect() : AiRequest::query()
            ->where('user_id', auth()->id())
            ->whereIn('id', $this->requestIds)
            ->orderBy('id')
            ->get();

        $suggestions = app(Workspace::class)->askHiveSuggestions();
        $key = match (auth()->user()->primaryRole()?->value) {
            'producer', 'head-of-department' => 'producer',
            'freelancer' => 'freelancer',
            'employee' => 'employee',
            'hr' => 'hr',
            default => 'default',
        };

        return view('livewire.ask-hive', [
            'conversation' => $requests->map(fn (AiRequest $r) => ['id' => $r->id, 'q' => $r->prompt, 'pending' => $r->isPending(), 'a' => $r->panelAnswer()])->all(),
            'waiting' => $requests->contains(fn (AiRequest $r) => $r->isPending()),
            'suggestions' => $suggestions[$key] ?? $suggestions['default'],
        ]);
    }
}
