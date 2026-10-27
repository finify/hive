<?php

namespace App\Services\AskHive;

/**
 * What Ask Hive is asked: free text from the panel, or a workflow started
 * from a screen with the record it is about.
 */
final class Question
{
    public function __construct(
        public readonly string $text,
        public readonly ?Workflow $workflow = null,
        public readonly ?string $subject = null,
    ) {}

    public static function workflow(Workflow $workflow, ?string $subject = null): self
    {
        return new self($workflow->question($subject), $workflow, $subject);
    }
}
