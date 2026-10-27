<?php

namespace App\Jobs;

use App\Actions\Ai\AskHive;
use App\Models\AiRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Answers one Ask Hive request off the web request, so a slow model never
 * holds a page open. The panel polls the request until it is answered.
 */
class AnswerAskHiveQuestion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $requestId) {}

    public function handle(AskHive $ask): void
    {
        $request = AiRequest::query()->find($this->requestId);

        if ($request) {
            $ask->answer($request);
        }
    }

    public function failed(?Throwable $e): void
    {
        AiRequest::query()->whereKey($this->requestId)->where('status', 'queued')->update([
            'status' => 'failed',
            'error' => 'Ask Hive couldn’t answer just now. Try again in a minute.',
        ]);
    }
}
