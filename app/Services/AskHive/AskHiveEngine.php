<?php

namespace App\Services\AskHive;

use App\Demo\Viewer;

/**
 * Answers a question using only the read-only tools, as the asking user.
 *
 * The demo uses LocalEngine (deterministic, no network). Phase 7 adds an
 * engine that calls the Anthropic Messages API with the same tools.
 */
interface AskHiveEngine
{
    public function name(): string;

    public function answer(string $question, Viewer $viewer, Tools $tools): Answer;
}
