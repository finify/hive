<?php

namespace App\Services\AskHive\Gateway;

/**
 * A model's reply, reduced to what the tool loop needs. Content blocks keep
 * the API's shape (text, tool_use, thinking...) so the assistant turn can be
 * sent back unchanged; `raw` holds the SDK's own blocks for that.
 */
final class ModelTurn
{
    /**
     * @param  list<array<string, mixed>>  $content
     * @param  list<mixed>|null  $raw
     */
    public function __construct(
        public readonly ?string $stopReason,
        public readonly array $content,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly string $model = '',
        public readonly ?string $refusal = null,
        public readonly ?array $raw = null,
    ) {}

    /**
     * The assistant turn to send back in the next request. The SDK's own
     * blocks travel with it so thinking signatures survive the round trip.
     *
     * @return array<string, mixed>
     */
    public function assistantMessage(): array
    {
        return array_filter(['role' => 'assistant', 'content' => $this->content, 'raw' => $this->raw], fn ($v) => $v !== null);
    }

    public function text(): string
    {
        return trim(implode("\n\n", array_map(
            fn (array $b) => (string) $b['text'],
            array_values(array_filter($this->content, fn (array $b) => ($b['type'] ?? null) === 'text')),
        )));
    }

    /**
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    public function toolUses(): array
    {
        $uses = [];

        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                $uses[] = ['id' => (string) $block['id'], 'name' => (string) $block['name'], 'input' => is_array($block['input'] ?? null) ? $block['input'] : []];
            }
        }

        return $uses;
    }
}
