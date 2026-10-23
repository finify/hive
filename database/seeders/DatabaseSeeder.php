<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->environment(['local', 'staging', 'testing'])) {
            $this->call(DemoDirectorySeeder::class);
            $this->call(DemoEventsSeeder::class);
            $this->call(DemoResourcingSeeder::class);
            $this->call(DemoUserSeeder::class);
            $this->call(DemoTimeSeeder::class);
        }
    }
}
