<?php

namespace App\Services\AskHive\Gateway;

/**
 * One round trip to a language model. ClaudeEngine runs the tool loop over
 * this, so tests can script the model and see every byte it would be sent.
 */
interface ModelGateway
{
    /**
     * @param  list<array<string, mixed>>  $messages  Anthropic Messages API turns, snake_case keys.
     * @param  list<array<string, mixed>>  $tools  Tool definitions, snake_case keys.
     */
    public function send(string $system, array $messages, array $tools): ModelTurn;

    public function model(): string;
}
