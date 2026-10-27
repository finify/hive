<?php

namespace App\Models\Concerns;

use App\Demo\WorkspaceVersion;

/**
 * Models the Workspace caches: any save or delete moves it to fresh data.
 */
trait TouchesWorkspace
{
    protected static function bootTouchesWorkspace(): void
    {
        static::saved(fn () => WorkspaceVersion::bump());
        static::deleted(fn () => WorkspaceVersion::bump());
    }
}
