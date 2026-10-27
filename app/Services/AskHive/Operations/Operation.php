<?php

namespace App\Services\AskHive\Operations;

use App\Models\User;

/**
 * A change Ask Hive can propose. It never runs on the model's say-so: the
 * proposal is checked with a dry run, shown to the person as a card, and
 * performed only when they confirm it, through the same Action as the
 * screen, as them.
 */
interface Operation
{
    /** The tool is called "propose_{name}". */
    public static function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed> JSON schema for the input
     */
    public function schema(): array;

    /** Whether to offer it to this person at all; the Action still decides. */
    public function offeredTo(User $user): bool;

    /**
     * What the card says, in plain English.
     *
     * @param  array<string, mixed>  $input
     */
    public function summary(User $user, array $input): string;

    /**
     * Does it, as the user. Returns what to tell them.
     *
     * @param  array<string, mixed>  $input
     */
    public function perform(User $user, array $input): string;
}
