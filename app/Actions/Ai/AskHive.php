<?php

namespace App\Actions\Ai;

use Anthropic\Core\Exceptions\APIStatusException;
use App\Demo\Insights;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Jobs\AnswerAskHiveQuestion;
use App\Models\AiRequest;
use App\Models\User;
use App\Services\AskHive\AskHiveEngine;
use App\Services\AskHive\Question;
use App\Services\AskHive\Tools;
use App\Services\AskHive\Workflow;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Asks Hive a question as this user. The request is logged and queued at
 * once; AnswerAskHiveQuestion answers it with the engine, which sees only
 * the tools, and the tools see only what the user may see. Rate limited per
 * user per hour.
 */
class AskHive
{
    public function __construct(
        private readonly AskHiveEngine $engine,
        private readonly Workspace $workspace,
    ) {}

    /**
     * @param  int|null  $previousId  the last request in the same panel conversation
     */
    public function handle(User $user, string $question, ?Workflow $workflow = null, ?string $subject = null, ?int $previousId = null): AiRequest
    {
        abort_unless(config('hive.ai.enabled'), 403);
        Gate::forUser($user)->authorize('ai.use');

        if ($workflow) {
            $subject = $subject !== null ? strtoupper(trim($subject)) : null;
            abort_unless($workflow->allows(new Viewer($user, $this->workspace), $this->workspace, $subject), 403);
            $question = $workflow->question($subject);
        } else {
            $question = trim($question);

            if (mb_strlen($question) < 3 || mb_strlen($question) > 500) {
                throw ValidationException::withMessages(['question' => 'Ask a question between 3 and 500 characters.']);
            }
        }

        $key = 'ask-hive:'.$user->id;
        $limit = (int) config('hive.ai.hourly_limit');

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['question' => "You’ve reached {$limit} questions this hour. Try again in {$minutes} minutes."]);
        }

        RateLimiter::hit($key, 3600);

        $request = AiRequest::create([
            'user_id' => $user->id,
            'previous_id' => $previousId !== null && AiRequest::query()->whereKey($previousId)->where('user_id', $user->id)->exists() ? $previousId : null,
            'engine' => $this->engine->name(),
            'status' => 'queued',
            'workflow' => $workflow?->value,
            'subject' => $subject,
            'model' => $this->engine->model(),
            'prompt' => $question,
        ]);

        AnswerAskHiveQuestion::dispatch($request->id);

        return $request->refresh();
    }

    /**
     * Answers a queued request. Failures are recorded on the request with a
     * message the panel can show; the details go to the error log.
     */
    public function answer(AiRequest $request): void
    {
        if (! $request->isPending()) {
            return;
        }

        $user = $request->user;
        $viewer = new Viewer($user, $this->workspace);
        $tools = new Tools($this->workspace, $viewer, new Insights($this->workspace, $viewer));
        $workflow = $request->workflow ? Workflow::from($request->workflow) : null;
        $history = $request->history();
        $question = $workflow ? Question::workflow($workflow, $request->subject, $history) : new Question($request->prompt, history: $history);

        $started = hrtime(true);

        try {
            $answer = $this->engine->answer($question, $viewer, $tools);
        } catch (Throwable $e) {
            report($e);
            $request->update([
                'status' => 'failed',
                'tools_called' => $tools->calls(),
                'error' => $this->failure($e),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            ]);

            return;
        }

        $request->record($answer, $this->engine->name(), $this->engine->model(), (int) ((hrtime(true) - $started) / 1_000_000));
    }

    private function failure(Throwable $e): string
    {
        if ($e instanceof APIStatusException && in_array($e->status, [429, 529], true)) {
            return 'Ask Hive is busy right now. Try again in a minute.';
        }

        return 'Ask Hive couldn’t answer just now. Try again in a minute; if it keeps happening, tell your Hive administrator.';
    }
}
