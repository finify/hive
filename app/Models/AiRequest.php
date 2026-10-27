<?php

namespace App\Models;

use App\Services\AskHive\Answer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every Ask Hive request and response, with the tools it called. A request
 * is queued when asked and answered by AnswerAskHiveQuestion.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $previous_id
 * @property string $engine
 * @property string $status queued, answered or failed
 * @property string|null $workflow
 * @property string|null $subject
 * @property string|null $model
 * @property string $prompt
 * @property list<array<string, mixed>>|null $tools_called
 * @property string|null $response
 * @property array<string, mixed>|null $answer
 * @property string|null $error
 * @property bool $refused
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $duration_ms
 */
class AiRequest extends Model
{
    protected $fillable = [
        'user_id', 'previous_id', 'engine', 'status', 'workflow', 'subject', 'model', 'prompt', 'tools_called', 'response', 'answer', 'error',
        'refused', 'input_tokens', 'output_tokens', 'duration_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tools_called' => 'array', 'answer' => 'array', 'refused' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The earlier turns of this conversation, oldest first, as text the
     * model can read: each answer with what happened to its proposals.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function history(int $turns = 6): array
    {
        $history = [];
        $previous = $this->previous_id ? self::query()->find($this->previous_id) : null;

        while ($previous !== null && $previous->user_id === $this->user_id && count($history) < $turns) {
            if ($previous->status === 'answered') {
                $outcomes = array_map(
                    fn (array $p) => '['.match ($p['status']) {
                        'done' => 'Confirmed and done: ',
                        'cancelled' => 'The user cancelled: ',
                        'failed' => 'Hive refused when confirmed: ',
                        default => 'Proposed, not yet confirmed: ',
                    }.$p['summary'].($p['message'] ? ' — '.$p['message'] : '').']',
                    $previous->proposals(),
                );
                array_unshift($history, ['question' => $previous->prompt, 'answer' => trim($previous->response."\n".implode("\n", $outcomes))]);
            }

            $previous = $previous->previous_id ? self::query()->find($previous->previous_id) : null;
        }

        return $history;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function proposals(): array
    {
        return array_values($this->answer['proposals'] ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function proposal(string $id): ?array
    {
        return collect($this->proposals())->firstWhere('id', $id);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateProposal(string $id, array $changes): void
    {
        $answer = $this->answer ?? [];
        $answer['proposals'] = array_map(fn (array $p) => $p['id'] === $id ? array_merge($p, $changes) : $p, $this->proposals());
        $this->update(['answer' => $answer]);
    }

    public function isPending(): bool
    {
        return $this->status === 'queued';
    }

    public function record(Answer $answer, string $engine, ?string $model, int $durationMs): void
    {
        $this->update([
            'status' => 'answered',
            'engine' => $engine,
            'model' => $model,
            'tools_called' => $answer->tools,
            'response' => $answer->text(),
            'answer' => $answer->toArray(),
            'refused' => $answer->refused,
            'input_tokens' => $answer->inputTokens,
            'output_tokens' => $answer->outputTokens,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * The answer as the panel shows it: paragraphs, bullets, sources, tools.
     *
     * @return array<string, mixed>
     */
    public function panelAnswer(): array
    {
        return match ($this->status) {
            'answered' => $this->answer ?? ['paragraphs' => [(string) $this->response], 'bullets' => [], 'sources' => [], 'tools' => [], 'refused' => $this->refused],
            'failed' => ['paragraphs' => [$this->error ?? 'Ask Hive couldn’t answer just now. Try again in a minute.'], 'bullets' => [], 'sources' => [], 'tools' => [], 'refused' => false, 'failed' => true],
            default => [],
        };
    }
}
