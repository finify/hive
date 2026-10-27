<?php

namespace App\Services\AskHive;

/**
 * What Ask Hive is asked: free text from the panel, or a workflow started
 * from a screen with the record it is about, plus the earlier turns of the
 * same conversation so follow-ups ("and the week after?", "book her")
 * make sense.
 */
final class Question
{
    /**
     * @param  list<array{question: string, answer: string}>  $history  oldest first
     */
    public function __construct(
        public readonly string $text,
        public readonly ?Workflow $workflow = null,
        public readonly ?string $subject = null,
        public readonly array $history = [],
    ) {}

    /**
     * @param  list<array{question: string, answer: string}>  $history
     */
    public static function workflow(Workflow $workflow, ?string $subject = null, array $history = []): self
    {
        return new self($workflow->question($subject), $workflow, $subject, $history);
    }
}
