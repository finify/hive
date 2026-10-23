<?php

namespace App\Livewire\Events;

use App\Livewire\Concerns\UsesWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Calendar')]
class Calendar extends Component
{
    use UsesWorkspace;

    #[Url(except: '')]
    public string $month = '';

    #[Url(except: 'month')]
    public string $view = 'month';

    public function mount(): void
    {
        $this->month = $this->month ?: $this->workspace()->today()->format('Y-m');
    }

    public function shift(int $by): void
    {
        $this->month = CarbonImmutable::parse($this->month.'-01')->addMonths($by)->format('Y-m');
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $first = CarbonImmutable::parse($this->month.'-01');
        $today = $workspace->today();

        if ($this->view === 'week') {
            $start = $today->startOfWeek();
            $end = $start->addDays(6);
        } else {
            $start = $first->startOfWeek();
            $end = $first->endOfMonth()->endOfWeek();
        }

        $events = $this->viewer()->events()->reject(fn ($e) => in_array($e['stage'], ['lost', 'cancelled'], true));
        $days = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $chips = [];

            foreach ($events as $event) {
                if ($event['start_date'] <= $date && $date <= $event['end_date']) {
                    $chips[] = ['event' => $event, 'label' => $event['code'].' '.$event['name'], 'phase' => false];

                    continue;
                }

                foreach ($event['phases'] as $phase) {
                    if ($phase['type'] !== 'live' && $phase['start_date'] <= $date && $date <= $phase['end_date']) {
                        $chips[] = ['event' => $event, 'label' => ucfirst($phase['type']).': '.$event['code'], 'phase' => true];
                        break;
                    }
                }
            }

            $days[] = ['date' => $day, 'chips' => $chips, 'outside' => $this->view === 'month' && $day->month !== $first->month, 'today' => $day->isSameDay($today)];
        }

        return view('livewire.events.calendar', ['days' => $days, 'first' => $first, 'today' => $today]);
    }
}
