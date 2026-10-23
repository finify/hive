<?php

namespace Database\Seeders;

use App\Demo\DemoData;
use App\Demo\Rows;
use App\Enums\DocumentType;
use App\Enums\EventType;
use App\Models\Document;
use App\Models\Event;
use App\Models\EventStageChange;
use App\Models\Task;
use App\Models\TaskTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Facades\CauserResolver;
use Spatie\Activitylog\Models\Activity;

/**
 * Loads events, their phases, team, stage history, tasks and files from
 * database/demo/events.json, keeping the JSON ids so bookings, budgets and
 * timesheets (still JSON) point at the right events. Also seeds the task
 * templates for every event type.
 */
class DemoEventsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data is for local, staging and test environments only.');
        }

        CauserResolver::setCauser(null);

        DB::transaction(function () {
            activity()->disableLogging();

            foreach (Rows::of(app(DemoData::class)->raw('events')) as $row) {
                $event = Event::query()->forceCreate([
                    'id' => $row['id'], 'code' => $row['code'], 'name' => $row['name'], 'client_id' => $row['client_id'],
                    'producer_id' => $row['producer_id'], 'event_type' => $row['event_type'], 'stage' => $row['stage'],
                    'venue_id' => $row['venue_id'], 'city' => $row['city'], 'country' => $row['country'], 'timezone' => $row['timezone'],
                    'start_date' => $row['start_date'], 'end_date' => $row['end_date'], 'expected_attendance' => $row['expected_attendance'],
                    'client_fee_minor' => $row['client_fee_minor'], 'currency' => $row['currency'], 'colour' => $row['colour'],
                    'description' => $row['description'],
                ]);

                foreach (Rows::of($row['phases']) as $phase) {
                    $event->phases()->create($phase);
                }

                foreach (Rows::of($row['team']) as $member) {
                    $event->team()->create($member);
                }

                foreach (Rows::of($row['stage_history']) as $change) {
                    EventStageChange::query()->create([
                        'event_id' => $event->id, 'from_stage' => $change['from'], 'to_stage' => $change['to'],
                        'changed_by' => $change['by'], 'note' => $change['note'], 'changed_at' => $change['at'],
                    ]);
                }

                foreach (Rows::of($row['tasks']) as $i => $task) {
                    Task::query()->forceCreate([
                        'id' => $task['id'], 'event_id' => $event->id, 'title' => $task['title'], 'assignee_id' => $task['assignee_id'],
                        'due_date' => $task['due_date'], 'status' => $task['status'], 'sort_order' => $i,
                    ]);
                }

                foreach (Rows::of($row['files']) as $file) {
                    $type = DocumentType::from($file['type']);
                    Document::query()->create([
                        'documentable_type' => Event::class, 'documentable_id' => $event->id, 'type' => $type,
                        'title' => $type === DocumentType::Contract ? 'Signed client contract' : $type->label(),
                        'original_name' => $file['name'], 'mandatory' => false,
                    ]);
                }
            }

            $this->templates();

            activity()->enableLogging();
        });

        Activity::query()->where('log_name', 'events')->delete();
    }

    /**
     * One standard task list per event type. Offsets are days from the
     * event's start; "Producer" items go to the producer, others to the team
     * member holding that role, or the producer if nobody does.
     */
    private function templates(): void
    {
        $core = [
            ['Contract countersigned', -120, 'Producer'],
            ['Kick-off meeting with client', -110, 'Producer'],
            ['Budget agreed and locked', -100, 'Finance contact'],
            ['Venue site visit', -90, 'Producer'],
            ['Supplier quotes in', -80, 'Production manager'],
            ['Purchase orders raised', -70, 'Production manager'],
            ['Crew calls issued', -60, 'Production manager'],
            ['Run of show v1 to client', -45, 'Producer'],
            ['Risk assessment signed off', -30, 'Production manager'],
            ['Final numbers to caterer', -7, 'Coordinator'],
            ['Call sheets sent', -3, 'Coordinator'],
            ['Wrap report', 10, 'Producer'],
            ['Supplier invoices matched', 21, 'Finance contact'],
        ];

        $extra = [
            EventType::Conference->value => [
                ['Agenda outline to client', -105, 'Producer'],
                ['Speaker invitations sent', -95, 'Coordinator'],
                ['Registration site live', -85, 'Registration lead'],
                ['Speaker contracts returned', -75, 'Coordinator'],
                ['AV and staging spec', -65, 'Technical production manager'],
                ['Hotel room block confirmed', -60, 'Logistics'],
                ['Graphics and holding slides brief', -55, 'Designer'],
                ['Floor plan v1', -50, 'Production manager'],
                ['Speaker briefing calls', -21, 'Producer'],
                ['Presentations collected', -10, 'Coordinator'],
                ['Badges printed', -5, 'Registration lead'],
                ['Rehearsal schedule shared', -4, 'Stage manager'],
                ['Delegate survey sent', 2, 'Coordinator'],
                ['Content and recordings to client', 7, 'Technical production manager'],
                ['Attendance report', 5, 'Registration lead'],
                ['Supplier feedback recorded', 14, 'Production manager'],
                ['Final client invoice', 30, 'Finance contact'],
            ],
            EventType::Exhibition->value => [
                ['Stand design approved', -90, 'Designer'],
                ['Organiser manual deadlines logged', -80, 'Production manager'],
                ['Stand build quote approved', -70, 'Production manager'],
                ['Freight booked', -40, 'Logistics'],
                ['Exhibitor badges ordered', -21, 'Coordinator'],
                ['Lead capture set up', -10, 'Coordinator'],
            ],
            EventType::ProductLaunch->value => [
                ['Creative concept signed off', -90, 'Designer'],
                ['Press list agreed', -60, 'Producer'],
                ['Content embargo agreed', -30, 'Producer'],
                ['Technical rehearsal', -2, 'Technical production manager'],
            ],
            EventType::ExecutiveSummit->value => [
                ['Guest list agreed', -90, 'Producer'],
                ['Invitations sent', -75, 'Coordinator'],
                ['Security plan', -30, 'Production manager'],
                ['Seating plan signed off', -7, 'Producer'],
            ],
            EventType::IncentiveTrip->value => [
                ['Flights held', -120, 'Logistics'],
                ['Passport and visa checks', -60, 'Logistics'],
                ['Rooming list to hotel', -30, 'Logistics'],
                ['Travel documents issued', -10, 'Coordinator'],
                ['Excursion suppliers confirmed', -45, 'Production manager'],
            ],
            EventType::BrandActivation->value => [
                ['Site permits applied for', -75, 'Production manager'],
                ['Brand ambassadors cast', -40, 'Coordinator'],
                ['Sampling stock delivered', -5, 'Logistics'],
                ['Footfall report', 7, 'Producer'],
            ],
            EventType::Hybrid->value => [
                ['Streaming platform booked', -75, 'Technical production manager'],
                ['Remote speaker tech checks', -7, 'Technical production manager'],
                ['Recordings to client', 7, 'Technical production manager'],
            ],
            EventType::Virtual->value => [
                ['Streaming platform booked', -60, 'Technical production manager'],
                ['Speaker tech checks', -7, 'Technical production manager'],
                ['Recordings to client', 5, 'Technical production manager'],
            ],
        ];

        foreach (EventType::cases() as $type) {
            $template = TaskTemplate::query()->create(['name' => "{$type->label()} standard", 'event_type' => $type]);
            $items = collect([...$core, ...($extra[$type->value] ?? [])])->sortBy(fn (array $item) => $item[1])->values();

            foreach ($items as $i => [$title, $offset, $role]) {
                $template->items()->create(['title' => $title, 'due_offset_days' => $offset, 'assignee_role' => $role, 'sort_order' => $i]);
            }
        }
    }
}
