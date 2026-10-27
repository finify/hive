<?php

namespace App\Services\AskHive\Operations;

use App\Demo\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * Shared lookups for operations: events by code, input helpers.
 */
abstract class Support implements Operation
{
    public function __construct(protected readonly Workspace $workspace) {}

    /**
     * @param  array<string, mixed>  $input
     */
    protected function str(array $input, string $key): ?string
    {
        $v = $input[$key] ?? null;

        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function int(array $input, string $key): ?int
    {
        $v = $input[$key] ?? null;

        return is_numeric($v) ? (int) $v : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function event(?string $code): array
    {
        $event = $code ? $this->workspace->eventByCode($code) : null;

        if ($event === null) {
            throw ValidationException::withMessages(['event_code' => 'There is no event '.($code ?? '').' in Hive.']);
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    protected static function object(array $properties, array $required = []): array
    {
        return array_filter(['type' => 'object', 'properties' => $properties, 'required' => $required ?: null]);
    }
}
