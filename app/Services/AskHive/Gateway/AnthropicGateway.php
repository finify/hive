<?php

namespace App\Services\AskHive\Gateway;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaRedactedThinkingBlock;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaThinkingBlock;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;

/**
 * Sends Ask Hive's turns to the Anthropic Messages API through the official
 * SDK. Adaptive thinking, an effort setting, prompt caching on the standing
 * instructions and tools, and server-side fallbacks when a model declines.
 */
class AnthropicGateway implements ModelGateway
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        private readonly Client $client,
        private readonly string $model,
        private readonly string $effort = 'medium',
        private readonly int $maxTokens = 8000,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            new Client(
                apiKey: (string) config('hive.ai.api_key'),
                requestOptions: ['timeout' => (float) config('hive.ai.timeout'), 'maxRetries' => 2],
            ),
            (string) config('hive.ai.model'),
            (string) config('hive.ai.effort'),
            (int) config('hive.ai.max_tokens'),
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    public function send(string $system, array $messages, array $tools): ModelTurn
    {
        $definitions = array_map(fn (array $t) => [
            'name' => $t['name'],
            'description' => $t['description'],
            'inputSchema' => $t['input_schema'],
        ], $tools);

        if ($definitions !== []) {
            $definitions[array_key_last($definitions)]['cacheControl'] = ['type' => 'ephemeral'];
        }

        $message = $this->client->beta->messages->create(
            maxTokens: $this->maxTokens,
            messages: array_map(fn (array $m) => $this->message($m), $messages),
            model: $this->model,
            system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
            thinking: ['type' => 'adaptive'],
            outputConfig: ['effort' => $this->effort],
            tools: $definitions,
            fallbacks: 'default',
            betas: [self::FALLBACK_BETA],
        );

        return $this->turn($message);
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function message(array $m): array
    {
        if ($m['role'] === 'assistant' && isset($m['raw'])) {
            // The SDK's own blocks, so thinking signatures go back untouched.
            return ['role' => 'assistant', 'content' => $m['raw']];
        }

        if (is_string($m['content'])) {
            return ['role' => $m['role'], 'content' => $m['content']];
        }

        return ['role' => $m['role'], 'content' => array_map(fn (array $b) => match ($b['type']) {
            'tool_result' => array_filter([
                'type' => 'tool_result',
                'toolUseID' => $b['tool_use_id'],
                'content' => $b['content'],
                'isError' => $b['is_error'] ?? null,
            ], fn ($v) => $v !== null),
            default => $b,
        }, $m['content'])];
    }

    private function turn(BetaMessage $message): ModelTurn
    {
        $content = [];

        foreach ($message->content as $block) {
            $content[] = match (true) {
                $block instanceof BetaTextBlock => ['type' => 'text', 'text' => $block->text],
                $block instanceof BetaToolUseBlock => ['type' => 'tool_use', 'id' => $block->id, 'name' => $block->name, 'input' => $block->input],
                $block instanceof BetaThinkingBlock => ['type' => 'thinking', 'thinking' => $block->thinking, 'signature' => $block->signature],
                $block instanceof BetaRedactedThinkingBlock => ['type' => 'redacted_thinking'],
                default => ['type' => (string) ($block->type ?? 'other')],
            };
        }

        $usage = $message->usage;

        return new ModelTurn(
            stopReason: $message->stopReason,
            content: $content,
            inputTokens: $usage->inputTokens + (int) $usage->cacheReadInputTokens + (int) $usage->cacheCreationInputTokens,
            outputTokens: $usage->outputTokens,
            model: $message->model,
            refusal: $message->stopReason === 'refusal' ? ($message->stopDetails->explanation ?? '') : null,
            raw: array_values($message->content),
        );
    }
}
