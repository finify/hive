<?php

namespace App\Livewire\Resourcing;

use App\Enums\AssignmentStatus;
use App\Livewire\Concerns\UsesWorkspace;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Rota')]
class Rota extends Component
{
    use UsesWorkspace;

    #[Url(except: 'week')]
    public string $view = 'week';

    #[Url(except: '')]
    public string $start = '';

    #[Url(except: '')]
    public string $department = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $skill = '';

    #[Url(except: true)]
    public bool $bookedOnly = true;

    public function mount(): void
    {
        $today = $this->workspace()->today();
        $this->start = $this->start ?: ($this->view === 'month' ? $today->startOfMonth() : $today->startOfWeek())->toDateString();
    }

    public function shift(int $by): void
    {
        $start = CarbonImmutable::parse($this->start);
        $this->start = ($this->view === 'month' ? $start->addMonths($by)->startOfMonth() : $start->addWeeks($by)->startOfWeek())->toDateString();
    }

    public function goToToday(): void
    {
        $today = $this->workspace()->today();
        $this->start = ($this->view === 'month' ? $today->startOfMonth() : $today->startOfWeek())->toDateString();
    }

    public function updatedView(): void
    {
        $start = CarbonImmutable::parse($this->start);
        $this->start = ($this->view === 'month' ? $start->startOfMonth() : $start->startOfWeek())->toDateString();
    }

    #[On('clash-resolved')]
    public function refreshRota(): void
    {
        $this->workspace()->forget();
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();
        $start = CarbonImmutable::parse($this->start);
        $end = $this->view === 'month' ? $start->endOfMonth() : $start->addDays(13);
        $range = new DateRange($start, $end);
        $dates = $range->dates();
        $index = array_flip($dates);
        $today = $workspace->today()->toDateString();

        $clashKeys = [];

        foreach ($workspace->clashes() as $clash) {
            $clashKeys[$clash->first->key()] = $clash->key();
            $clashKeys[$clash->second->key()] = $clash->key();
        }

        $people = $workspace->people()
            ->only($viewer->rotaPeople())
            ->where('is_active', true)
            ->when($this->department !== '', fn ($c) => $c->where('department_id', (int) $this->department))
            ->when($this->type === 'staff', fn ($c) => $c->where('employment_type', '!=', 'freelancer'))
            ->when($this->type === 'freelance', fn ($c) => $c->where('employment_type', 'freelancer'))
            ->when($this->skill !== '', fn ($c) => $c->filter(fn ($p) => $workspace->skillLevel($p['id'], (int) $this->skill) > 0));

        $rows = [];

        foreach ($people as $person) {
            $bars = [];

            foreach ($workspace->assignments()->where('person_id', $person['id']) as $a) {
                $status = AssignmentStatus::from($a['status']);

                if (! $status->holdsPerson()) {
                    continue;
                }

                $event = $workspace->event($a['event_id']);
                $bars[] = $this->bar('assignment', $a['id'], $a['start_date'], $a['end_date'], $range, $index, [
                    'kind' => $status->value,
                    'label' => $event['code'],
                    'title' => "{$event['name']}, {$status->label()}",
                    'event' => $event,
                    'clash' => $clashKeys['assignment:'.$a['id']] ?? null,
                ]);
            }

            foreach ($workspace->leaveRequests()->where('person_id', $person['id']) as $leave) {
                if (! in_array($leave['status'], ['approved', 'pending'], true)) {
                    continue;
                }

                $label = $viewer->leaveLabel($leave);
                $sensitive = $label === 'Absent' || ($workspace->leaveType($leave['leave_type'])['is_sensitive'] ?? false);
                $bars[] = $this->bar('leave', $leave['id'], $leave['start_date'], $leave['end_date'], $range, $index, [
                    'kind' => $leave['status'] === 'pending' ? 'pending-leave' : ($sensitive ? 'absent' : 'leave'),
                    'label' => $leave['status'] === 'pending' ? $label.' (requested)' : $label,
                    'title' => $label.($leave['status'] === 'pending' ? ', waiting for approval' : ''),
                    'event' => null,
                    'clash' => $clashKeys['leave:'.$leave['id']] ?? null,
                ]);
            }

            foreach ($workspace->unavailability()->where('person_id', $person['id']) as $u) {
                $bars[] = $this->bar('unavailable', $u['id'], $u['start_date'], $u['end_date'], $range, $index, [
                    'kind' => 'unavailable', 'label' => 'Unavailable', 'title' => 'Marked unavailable', 'event' => null,
                    'clash' => $clashKeys['unavailable:'.$u['id']] ?? null,
                ]);
            }

            $bars = array_values(array_filter($bars));

            if ($this->bookedOnly && $bars === []) {
                continue;
            }

            // Overlapping bars go on separate lanes so a clash shows both bookings.
            usort($bars, fn ($a, $b) => $a['col'] <=> $b['col']);
            $laneEnds = [];

            foreach ($bars as &$bar) {
                $lane = 0;

                while (isset($laneEnds[$lane]) && $laneEnds[$lane] >= $bar['col']) {
                    $lane++;
                }

                $laneEnds[$lane] = $bar['col'] + $bar['span'] - 1;
                $bar['lane'] = $lane + 1;
            }
            unset($bar);

            $rows[] = [
                'person' => $person,
                'bars' => $bars,
                'lanes' => max(1, count($laneEnds)),
                'utilisation' => $workspace->utilisation($person['id'], $range),
            ];
        }

        // Busiest departments first, so the bookings that matter are on screen.
        $groups = collect($rows)
            ->groupBy(fn ($r) => $workspace->departmentName($r['person']['department_id']))
            ->sortByDesc(fn ($group) => $group->sum(fn ($r) => count($r['bars'])));

        return view('livewire.resourcing.rota', [
            'dates' => array_map(fn ($d) => CarbonImmutable::parse($d), $dates),
            'groups' => $groups,
            'today' => $today,
            'range' => $range,
            'departments' => $workspace->departments(),
            'skills' => $workspace->skills()->sortBy('name'),
            'clashCount' => count(array_filter($workspace->clashes(), fn ($c) => $c->dates->overlaps($range) && ($viewer->canSeeWholeRota() || $viewer->isSelf($c->personId)))),
            'peopleCount' => count($rows),
            'whole' => $viewer->canSeeWholeRota(),
        ]);
    }

    /**
     * @param  array<string, int>  $index
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>|null
     */
    private function bar(string $type, int $id, string $from, string $to, DateRange $range, array $index, array $extra): ?array
    {
        $dates = DateRange::of($from, $to);
        $visible = $dates->intersect($range);

        if (! $visible) {
            return null;
        }

        $col = $index[$visible->start->toDateString()];
        $span = $visible->lengthInDays();

        return $extra + ['type' => $type, 'id' => $id, 'col' => $col, 'span' => $span, 'dates' => $dates];
    }
}
