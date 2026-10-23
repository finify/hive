<?php

namespace App\Actions\Resourcing;

use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Enums\EventStage;
use App\Support\Display;
use Illuminate\Validation\ValidationException;

/**
 * Confirms a hold. Holds become Confirmed only on events that are Confirmed
 * or later, never over approved leave, and not while mandatory documents
 * have expired (when the document rule is set to block).
 */
class ConfirmAssignment
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @return list<string> reasons the hold cannot be confirmed
     */
    public function blockers(int $assignmentId): array
    {
        $assignment = $this->workspace->assignment($assignmentId);
        $event = $assignment ? $this->workspace->event($assignment['event_id']) : null;

        if (! $assignment || ! $event) {
            return ['That booking no longer exists.'];
        }

        $reasons = [];

        if (! EventStage::from($event['stage'])->isConfirmedOrLater() || in_array($event['stage'], [EventStage::Cancelled->value, EventStage::Lost->value], true)) {
            $reasons[] = "{$event['code']} is not confirmed yet, so this stays a hold.";
        }

        foreach ($this->workspace->leaveRequests() as $leave) {
            if ($leave['person_id'] === $assignment['person_id'] && $leave['status'] === 'approved'
                && $leave['start_date'] <= $assignment['end_date'] && $assignment['start_date'] <= $leave['end_date']) {
                $reasons[] = 'Approved leave from '.Display::date($leave['start_date']).' to '.Display::date($leave['end_date']).'.';
            }
        }

        if (config('hive.documents.expired_mandatory', 'block') === 'block') {
            foreach ($this->workspace->expiredMandatoryDocuments($assignment['person_id']) as $doc) {
                $reasons[] = "{$doc['title']} expired on ".Display::date($doc['expires_at']).'.';
            }
        }

        return $reasons;
    }

    public function canConfirm(int $assignmentId): bool
    {
        return $this->blockers($assignmentId) === [];
    }

    public function handle(int $assignmentId): AssignmentStatus
    {
        $blockers = $this->blockers($assignmentId);

        if ($blockers !== []) {
            throw ValidationException::withMessages(['assignment' => $blockers]);
        }

        $this->workspace->ledger->put('assignments', $assignmentId, array_merge(
            $this->workspace->ledger->section('assignments')[$assignmentId] ?? [],
            ['status' => AssignmentStatus::Confirmed->value],
        ));
        $this->workspace->forget();

        return AssignmentStatus::Confirmed;
    }
}
