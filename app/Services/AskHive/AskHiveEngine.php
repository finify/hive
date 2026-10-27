<?php

namespace App\Services\AskHive;

use App\Demo\Viewer;

/**
 * Answers a question using only the read-only tools, as the asking user.
 *
 * LocalEngine is deterministic and needs no network (tests, and the demo
 * without an API key); ClaudeEngine calls the Anthropic Messages API with the
 * same tools.
 */
interface AskHiveEngine
{
    public function name(): string;

    /** The model behind the engine, for the log; null when there is none. */
    public function model(): ?string;

    public function answer(Question $question, Viewer $viewer, Tools $tools): Answer;
}
