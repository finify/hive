<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every Ask Hive request and response, with the tools it called.
 *
 * @property int $id
 * @property int $user_id
 * @property string $engine
 * @property string $prompt
 * @property list<array<string, mixed>>|null $tools_called
 * @property string|null $response
 * @property bool $refused
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $duration_ms
 */
class AiRequest extends Model
{
    protected $fillable = ['user_id', 'engine', 'prompt', 'tools_called', 'response', 'refused', 'input_tokens', 'output_tokens', 'duration_ms'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tools_called' => 'array', 'refused' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
