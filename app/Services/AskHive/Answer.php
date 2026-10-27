<?php

namespace App\Services\AskHive;

/**
 * An Ask Hive answer: plain paragraphs and bullets (never HTML), the records
 * it used, the tools it called, and any changes it proposes for the user to
 * confirm.
 */
final class Answer
{
    /**
     * @param  list<string>  $paragraphs
     * @param  list<string>  $bullets
     * @param  list<array{label: string, href: string}>  $sources
     * @param  list<array{tool: string, input: array<string, mixed>, allowed: bool}>  $tools
     * @param  list<array<string, mixed>>  $proposals
     */
    public function __construct(
        public readonly array $paragraphs,
        public readonly array $bullets = [],
        public readonly array $sources = [],
        public readonly array $tools = [],
        public readonly bool $refused = false,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly array $proposals = [],
    ) {}

    public function text(): string
    {
        return trim(implode("\n\n", $this->paragraphs)."\n".implode("\n", array_map(fn ($b) => '- '.$b, $this->bullets)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'paragraphs' => $this->paragraphs,
            'bullets' => $this->bullets,
            'sources' => $this->sources,
            // What it looked at; proposed changes show as their own cards.
            'tools' => array_values(array_filter(array_map(fn ($t) => $t['tool'], $this->tools), fn ($t) => ! str_starts_with($t, 'propose_'))),
            'refused' => $this->refused,
            'proposals' => $this->proposals,
        ];
    }
}
