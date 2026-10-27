<?php

namespace Tests\Feature\AskHive;

use App\Services\AskHive\Gateway\ModelGateway;
use App\Services\AskHive\Gateway\ModelTurn;
use Closure;

/**
 * A scripted model. Each step gets the messages so far and returns the next
 * turn; every request is kept so tests can see exactly what the model saw.
 */
class FakeGateway implements ModelGateway
{
    /** @var list<array{system: string, messages: list<array<string, mixed>>, tools: list<array<string, mixed>>}> */
    public array $requests = [];

    /** @var list<Closure(list<array<string, mixed>>): ModelTurn> */
    private array $steps;

    /**
     * @param  Closure(list<array<string, mixed>>): ModelTurn  ...$steps
     */
    public function __construct(Closure ...$steps)
    {
        $this->steps = array_values($steps);
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function send(string $system, array $messages, array $tools): ModelTurn
    {
        $this->requests[] = compact('system', 'messages', 'tools');
        $step = $this->steps[count($this->requests) - 1] ?? end($this->steps);

        return $step($messages);
    }

    /**
     * Everything that would have gone over the wire, as one string.
     */
    public function sent(): string
    {
        return (string) json_encode($this->requests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, array<string, mixed>>  $calls  tool name => input
     */
    public static function useTools(array $calls): ModelTurn
    {
        $content = [];
        $i = 0;

        foreach ($calls as $name => $input) {
            $content[] = ['type' => 'tool_use', 'id' => 'toolu_'.++$i, 'name' => preg_replace('/#\d+$/', '', $name), 'input' => $input];
        }

        return new ModelTurn('tool_use', $content, 100, 20);
    }

    public static function say(string $text): ModelTurn
    {
        return new ModelTurn('end_turn', [['type' => 'text', 'text' => $text]], 200, 50);
    }

    /**
     * The decoded tool results in the last user turn.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public static function results(array $messages): array
    {
        $last = end($messages);

        return array_map(fn ($b) => json_decode($b['content'], true), is_array($last['content']) ? $last['content'] : []);
    }
}
