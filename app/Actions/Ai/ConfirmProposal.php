<?php

namespace App\Actions\Ai;

use App\Models\AiRequest;
use App\Models\User;
use App\Services\AskHive\Proposals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Confirms or cancels a change Ask Hive proposed. Confirming runs the real
 * Action as the user who asked, so it authorises, validates, logs and
 * notifies exactly as the screen would; nothing is taken on the model's
 * word. A proposal can be confirmed once, by its own user, within
 * Proposals::EXPIRES_MINUTES.
 */
class ConfirmProposal
{
    public function __construct(private readonly Proposals $proposals) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function handle(User $user, int $requestId, string $proposalId): array
    {
        Gate::forUser($user)->authorize('ai.use');

        // One confirmation at a time per proposal, so a double click can't
        // run the Action twice.
        $lock = Cache::lock("ask-hive-proposal:{$proposalId}", 30);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['proposal' => 'That change is already being made.']);
        }

        try {
            $request = $this->request($user, $requestId);
            $proposal = $this->pending($request, $proposalId);
            $operation = $this->proposals->operation((string) $proposal['operation']);

            if ($operation === null || ! $operation->offeredTo($user)) {
                return $this->settle($user, $request, $proposal, 'failed', 'Your role can’t do that in Hive.');
            }

            try {
                $message = $operation->perform($user, (array) $proposal['input']);
            } catch (Throwable $e) {
                return $this->settle($user, $request, $proposal, 'failed', Proposals::explain($e));
            }

            return $this->settle($user, $request, $proposal, 'done', $message);
        } finally {
            $lock->release();
        }
    }

    public function cancel(User $user, int $requestId, string $proposalId): void
    {
        $request = $this->request($user, $requestId);
        $proposal = $this->pending($request, $proposalId);
        $this->settle($user, $request, $proposal, 'cancelled', 'Cancelled. Nothing changed.');
    }

    private function request(User $user, int $requestId): AiRequest
    {
        return AiRequest::query()->whereKey($requestId)->where('user_id', $user->id)->first()
            ?? throw ValidationException::withMessages(['proposal' => 'That change isn’t yours to confirm.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function pending(AiRequest $request, string $proposalId): array
    {
        $proposal = $request->proposal($proposalId);

        if ($proposal === null || $proposal['status'] !== 'pending') {
            throw ValidationException::withMessages(['proposal' => 'That change has already been dealt with.']);
        }

        if (Carbon::parse((string) $proposal['proposed_at'])->addMinutes(Proposals::EXPIRES_MINUTES)->isPast()) {
            $request->updateProposal($proposalId, ['status' => 'expired', 'message' => 'This proposal has expired. Ask again if you still want it.']);

            throw ValidationException::withMessages(['proposal' => 'This proposal has expired. Ask again if you still want it.']);
        }

        return $proposal;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array{ok: bool, message: string}
     */
    private function settle(User $user, AiRequest $request, array $proposal, string $status, string $message): array
    {
        $request->updateProposal((string) $proposal['id'], ['status' => $status, 'message' => $message, 'settled_at' => now()->toIso8601String()]);

        activity('ai')
            ->causedBy($user)
            ->performedOn($request)
            ->withProperties([
                'ref' => 'AI-'.$request->id,
                'old' => ['status' => 'pending'],
                'attributes' => ['status' => $status, 'operation' => $proposal['operation'], 'input' => $proposal['input'], 'message' => $message],
            ])
            ->event('proposal-'.$status)
            ->log(match ($status) {
                'done' => 'Confirmed an Ask Hive change: '.$proposal['summary'],
                'cancelled' => 'Cancelled an Ask Hive change: '.$proposal['summary'],
                default => 'An Ask Hive change was refused: '.$proposal['summary'],
            });

        return ['ok' => $status !== 'failed', 'message' => $message];
    }
}
