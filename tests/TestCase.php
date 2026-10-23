<?php

namespace Tests;

use App\Demo\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed roles, permissions and the demo personas before each feature test.
     */
    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;

    /**
     * The demo workspace for the current request cycle, freshly read.
     */
    protected function workspace(): Workspace
    {
        $workspace = app(Workspace::class);
        $workspace->forget();

        return $workspace;
    }
}
