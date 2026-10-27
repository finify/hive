<?php

namespace App\Services\AskHive;

use App\Demo\Viewer;
use App\Demo\Workspace;

/**
 * The four drafting jobs Ask Hive does from a screen (section 7): each one is
 * a fixed question the user can read, answered with the same read-only tools.
 */
enum Workflow: string
{
    case TimesheetSummary = 'timesheet-summary';
    case BudgetRisk = 'budget-risk';
    case WrapReport = 'wrap-report';
    case CrewSuggestions = 'crew-suggestions';

    public function label(): string
    {
        return match ($this) {
            self::TimesheetSummary => 'Summarise timesheets',
            self::BudgetRisk => 'Explain budget risk',
            self::WrapReport => 'Draft wrap report',
            self::CrewSuggestions => 'Suggest crew',
        };
    }

    /**
     * The question as the user sees it in the panel and in the log.
     */
    public function question(?string $subject): string
    {
        return match ($this) {
            self::TimesheetSummary => 'Summarise the timesheet entries waiting for my approval, and what to check first.',
            self::BudgetRisk => "Explain the budget risk on {$subject}: what is driving the forecast and what to look at.",
            self::WrapReport => "Draft a wrap report for {$subject}.",
            self::CrewSuggestions => "Suggest crew for the open places on {$subject}.",
        };
    }

    /**
     * What the model is asked to produce, on top of the standing rules.
     */
    public function instructions(): string
    {
        return match ($this) {
            self::TimesheetSummary => 'Call list_pending_timesheet_entries. Group by event, give totals of entries and hours, then list the flagged entries first with the person, date and the flag. End with one line on what to check before approving. Do not approve or recommend approving anything.',
            self::BudgetRisk => 'Call get_budget_health for the event. In two or three short paragraphs, say how the forecast compares with the budget, which lines are over and why it matters for margin, then give bullets of what the producer could check. Use only the figures the tool returns.',
            self::WrapReport => 'Call get_event_wrap_facts for the event. Write a draft wrap report a producer could edit: a one-paragraph summary, then bullets for delivery (dates, phases, crew), open items (unfinished tasks, disputes) and, only if the tool returns them, the financial outcome. Mark it as a draft.',
            self::CrewSuggestions => 'Call list_crew_calls for the event, then find_available_people for each call with open places, using the call’s role and dates. Give one bullet per open call with up to three names, best match first, and why. Never book anyone; the producer decides.',
        };
    }

    /**
     * Whether the user may run this workflow on this subject at all. The
     * tools still check every read; this keeps the buttons honest.
     */
    public function allows(Viewer $viewer, Workspace $workspace, ?string $subject): bool
    {
        if ($this === self::TimesheetSummary) {
            return $viewer->can('timesheets.approve');
        }

        $event = $subject ? $workspace->eventByCode($subject) : null;

        if ($event === null || ! $viewer->canSeeEvent($event)) {
            return false;
        }

        return match ($this) {
            self::BudgetRisk => $viewer->canSeeBudget($event),
            self::WrapReport => $viewer->can('events.edit') || $viewer->canSeeBudget($event),
            self::CrewSuggestions => $viewer->can('crew.manage'),
        };
    }
}
