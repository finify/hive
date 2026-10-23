<?php

namespace App\Livewire\Resourcing;

use App\Actions\Resourcing\AcceptClash;
use App\Actions\Resourcing\ResolveClash;
use App\Data\Booking;
use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The resolve flow: pick which booking moves, see who is free with the same
 * role and skill, and move the clashing days to them. Or keep both with a
 * recorded reason.
 */
class ResolveClashModal extends Component
{
    use UsesWorkspace;

    public string $clashKey = '';

    public ?int $assignmentId = null;

    public ?int $replacementId = null;

    public string $mode = 'move';

    public string $reason = '';

    #[On('resolve-clash')]
    public function open(string $key): void
    {
        $this->resetValidation();
        $this->clashKey = $key;
        $this->replacementId = null;
        $this->reason = '';
        $this->mode = 'move';
        $this->assignmentId = $this->movableAssignments()[0]['id'] ?? null;
        $this->dispatch('modal', id: 'resolve-modal', show: true);
    }

    public function updatedAssignmentId(): void
    {
        $this->replacementId = null;
    }

    /**
     * Assignments in the clash this user may move, preferring ones on events
     * they produce.
     *
     * @return list<array<string, mixed>>
     */
    private function movableAssignments(): array
    {
        $clash = $this->workspace()->findClash($this->clashKey);

        if (! $clash) {
            return [];
        }

        $out = [];

        foreach ([$clash->first, $clash->second] as $booking) {
            if ($booking->kind !== Booking::ASSIGNMENT) {
                continue;
            }

            $assignment = $this->workspace()->assignment((int) $booking->id);

            if ($assignment && auth()->user()->can('assignment.reassign', [$assignment])) {
                $out[] = $assignment;
            }
        }

        usort($out, fn ($a, $b) => (int) ! $this->viewer()->isProducerOf($this->workspace()->event($a['event_id'])) <=> (int) ! $this->viewer()->isProducerOf($this->workspace()->event($b['event_id'])));

        return $out;
    }

    public function resolve(ResolveClash $resolve): void
    {
        $this->resetValidation();
        $this->validate(['assignmentId' => ['required', 'integer'], 'replacementId' => ['required', 'integer']], [
            'replacementId.required' => 'Choose who takes over these days.',
        ]);

        $clash = $this->workspace()->findClash($this->clashKey);
        $from = $this->workspace()->personName($clash?->personId);
        $to = $this->workspace()->personName($this->replacementId);

        try {
            $resolve->handle(auth()->user(), $this->clashKey, (int) $this->assignmentId, (int) $this->replacementId);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->dispatch('modal', id: 'resolve-modal', show: false);
        $this->dispatch('clash-resolved');
        $this->toast("Clash resolved. {$from} and {$to} have both been told.");
    }

    public function keepBoth(AcceptClash $accept): void
    {
        $this->resetValidation();
        try {
            $accept->handle(auth()->user(), $this->clashKey, (int) $this->assignmentId, $this->reason);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->dispatch('modal', id: 'resolve-modal', show: false);
        $this->dispatch('clash-resolved');
        $this->toast('Both bookings kept. The reason is in the activity log.');
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $clash = $this->clashKey ? $workspace->findClash($this->clashKey) : null;
        $movable = $clash ? $this->movableAssignments() : [];
        $assignment = $this->assignmentId ? $workspace->assignment($this->assignmentId) : null;
        $call = $assignment ? $workspace->crewCalls()->get($assignment['crew_call_id']) : null;

        $suggestions = $clash && $call
            ? $workspace->availablePeople($call['role_name'], $clash->dates, $call['skill_id'], (int) ($call['min_skill_level'] ?? 0), exclude: [$clash->personId], preferCountry: $workspace->event($assignment['event_id'])['country'] ?? null)->take(5)
            : collect();

        return view('livewire.resourcing.resolve-clash-modal', [
            'clash' => $clash,
            'movable' => $movable,
            'assignment' => $assignment,
            'call' => $call,
            'suggestions' => $suggestions,
            'showRates' => auth()->user()->can('rates.view'),
        ]);
    }
}
