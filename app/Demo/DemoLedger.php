<?php

namespace App\Demo;

use Illuminate\Contracts\Session\Session;

/**
 * Changes made during a demo walkthrough, held in the session on top of the
 * read-only JSON data. Signing out or "Reset demo" clears them.
 */
class DemoLedger
{
    private const KEY = 'hive.demo.ledger';

    public function __construct(private readonly Session $session) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->session->get(self::KEY, []);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function section(string $section): array
    {
        return $this->all()[$section] ?? [];
    }

    public function put(string $section, int|string $id, mixed $value): void
    {
        $all = $this->all();
        $all[$section][$id] = $value;
        $this->session->put(self::KEY, $all);
    }

    public function push(string $section, mixed $value): void
    {
        $all = $this->all();
        $all[$section][] = $value;
        $this->session->put(self::KEY, $all);
    }

    public function nextId(string $sequence, int $start): int
    {
        $all = $this->all();
        $next = ($all['sequences'][$sequence] ?? $start) + 1;
        $all['sequences'][$sequence] = $next;
        $this->session->put(self::KEY, $all);

        return $next;
    }

    public function count(): int
    {
        $all = $this->all();
        unset($all['sequences']);

        return array_sum(array_map('count', $all));
    }

    public function reset(): void
    {
        $this->session->forget(self::KEY);
    }
}
