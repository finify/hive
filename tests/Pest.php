<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * A demo persona from database/demo/personas.json, by first name.
 */
function persona(string $firstName): User
{
    $emails = [
        'priya' => 'priya@lumenco.test', 'hannah' => 'hannah@lumenco.test', 'marcus' => 'marcus@lumenco.test',
        'grace' => 'grace@lumenco.test', 'leah' => 'leah@lumenco.test', 'tomasz' => 'tomasz@lumenco.test',
        'jamie' => 'jamie@lumenco.test', 'kofi' => 'kofi@freelance.test', 'sam' => 'sam@lumenco.test',
    ];

    return User::query()->where('email', $emails[$firstName])->firstOrFail();
}
