<?php

namespace Database\Seeders;

use App\Actions\Hr\StartChecklist;
use App\Demo\DemoData;
use App\Enums\ChecklistKind;
use App\Models\Checklist;
use App\Models\ChecklistTemplate;
use App\Models\Person;
use Illuminate\Database\Seeder;

/**
 * Onboarding and offboarding templates per employment type, and checklists
 * for the people starting or leaving around the demo date, with the items
 * due before today already done.
 */
class DemoChecklistSeeder extends Seeder
{
    /** @var array<string, array<string, list<array{0: string, 1: string, 2: int}>>> title, owner, days from the date */
    private const TEMPLATES = [
        'onboarding' => [
            'employee' => [
                ['Contract signed', 'hr', -21], ['Right to work check recorded', 'hr', -14], ['Bank details received by payroll', 'finance', -7],
                ['Accounts created', 'it', -3], ['Laptop issued', 'it', 0], ['Induction booked', 'line-manager', -5],
                ['First week plan shared', 'line-manager', -2], ['Three-month review booked', 'line-manager', 14],
            ],
            'fixed-term' => [
                ['Contract signed, with end date', 'hr', -14], ['Right to work check recorded', 'hr', -7], ['Bank details received by payroll', 'finance', -7],
                ['Accounts created', 'it', -3], ['Laptop issued', 'it', 0], ['Induction booked', 'line-manager', -2],
            ],
            'freelancer' => [
                ['Right to work check recorded', 'hr', -5], ['Insurance documents checked', 'hr', -5], ['NDA signed', 'hr', -5],
                ['Day rates and payment terms recorded', 'finance', -3], ['Self-billing agreement or invoicing details', 'finance', -3],
                ['Added to crew lists', 'line-manager', 0],
            ],
            'agency-temp' => [
                ['Agency agreement on file', 'hr', -3], ['Building access', 'it', 0], ['Induction', 'line-manager', 0],
            ],
        ],
        'offboarding' => [
            'employee' => [
                ['Notice recorded', 'hr', -28], ['Handover plan agreed', 'line-manager', -14], ['Final pay confirmed', 'finance', -5],
                ['Exit interview', 'hr', -3], ['Equipment returned', 'it', 0], ['Accounts disabled', 'it', 0],
            ],
            'fixed-term' => [
                ['Handover plan agreed', 'line-manager', -14], ['Final pay confirmed', 'finance', -5], ['Exit interview', 'hr', -3],
                ['Equipment returned', 'it', 0], ['Accounts disabled', 'it', 0],
            ],
            'freelancer' => [
                ['Accounts disabled', 'it', 0], ['Equipment returned', 'it', 0], ['Final invoice settled', 'finance', 30],
            ],
            'agency-temp' => [
                ['Building access removed', 'it', 0], ['Timesheets closed with the agency', 'finance', 7],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $kind => $types) {
            foreach ($types as $type => $items) {
                $template = ChecklistTemplate::query()->create([
                    'kind' => $kind, 'employment_type' => $type,
                    'name' => ucfirst($kind).', '.str_replace('-', ' ', $type),
                ]);

                foreach ($items as $i => [$title, $owner, $offset]) {
                    $template->items()->create(['title' => $title, 'owner' => $owner, 'due_offset_days' => $offset, 'position' => $i]);
                }
            }
        }

        $today = app(DemoData::class)->today();
        $start = app(StartChecklist::class);

        foreach (Person::query()->whereDate('start_date', '>=', $today->subDays(30)->toDateString())->get() as $person) {
            $this->tickPastItems($start->handle(null, $person, ChecklistKind::Onboarding, quiet: true), $today->toDateString());
        }

        foreach (Person::query()->whereNotNull('end_date')->whereDate('end_date', '>=', $today->toDateString())->get() as $person) {
            $this->tickPastItems($start->handle(null, $person, ChecklistKind::Offboarding, quiet: true), $today->toDateString());
        }
    }

    private function tickPastItems(Checklist $checklist, string $today): void
    {
        $checklist->items()->whereDate('due_date', '<', $today)->update(['completed_at' => now()]);
    }
}
