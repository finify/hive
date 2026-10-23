<?php

namespace App\Actions\Ai;

use App\Demo\Insights;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Models\AiRequest;
use App\Models\User;
use App\Services\AskHive\Answer;
use App\Services\AskHive\AskHiveEngine;
use App\Services\AskHive\Tools;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Asks Hive a question as this user. The engine sees only the tools, and the
 * tools see only what the user may see. Every request is logged with the
 * tools it called, and rate limited per user per hour.
 */
class AskHive
{
    public function __construct(
        private readonly AskHiveEngine $engine,
        private readonly Workspace $workspace,
    ) {}

    public function handle(User $user, string $question): Answer
    {
        abort_unless(config('hive.ai.enabled') && $user->can('ai.use'), 403);

        $question = trim($question);

        if (mb_strlen($question) < 3 || mb_strlen($question) > 500) {
            throw ValidationException::withMessages(['question' => 'Ask a question between 3 and 500 characters.']);
        }

        $key = 'ask-hive:'.$user->id;
        $limit = (int) config('hive.ai.hourly_limit');

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['question' => "You’ve reached {$limit} questions this hour. Try again in {$minutes} minutes."]);
        }

        RateLimiter::hit($key, 3600);

        $viewer = new Viewer($user, $this->workspace);
        $tools = new Tools($this->workspace, $viewer, new Insights($this->workspace, $viewer));

        $started = hrtime(true);
        $answer = $this->engine->answer($question, $viewer, $tools);
        $duration = (int) ((hrtime(true) - $started) / 1_000_000);

        AiRequest::create([
            'user_id' => $user->id,
            'engine' => $this->engine->name(),
            'prompt' => $question,
            'tools_called' => $answer->tools,
            'response' => $answer->text(),
            'refused' => $answer->refused,
            'input_tokens' => $answer->inputTokens,
            'output_tokens' => $answer->outputTokens,
            'duration_ms' => $duration,
        ]);

        return $answer;
    }
}
