<?php

namespace App\Demo;

use Illuminate\Support\Facades\Cache;

/**
 * The version of the data the Workspace caches between requests. Anything
 * that changes it bumps the version, so every reader moves to fresh data:
 * Workspace::forget() after an Action, and the saved and deleted events of
 * the models it reads (see TouchesWorkspace).
 */
final class WorkspaceVersion
{
    private const KEY = 'workspace.version';

    public static function current(): int
    {
        return (int) Cache::rememberForever(self::KEY, fn () => 1);
    }

    public static function bump(): void
    {
        Cache::forever(self::KEY, self::current() + 1);
    }
}
