<?php

namespace App\Livewire\Resourcing;

use App\Livewire\Concerns\UsesWorkspace;
use App\Support\DateRange;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Availability')]
class Availability extends Component
{
    use UsesWorkspace;

    #[Url(except: '')]
    #[Validate('required|string')]
    public string $role = '';

    #[Url(except: '')]
    #[Validate('required|date')]
    public string $from = '';

    #[Url(except: '')]
    #[Validate('required|date|after_or_equal:from')]
    public string $to = '';

    #[Url(except: '')]
    public string $skill = '';

    #[Url(except: 0)]
    public int $level = 0;

    #[Url(except: '')]
    public string $country = '';

    /** Set when the search was opened from a crew call, to book from the results. */
    #[Url(except: null)]
    public ?int $call = null;

    public function mount(): void
    {
        $today = $this->workspace()->today();
        $this->role = $this->role ?: 'AV Technician';
        $this->from = $this->from ?: $today->addWeeks(2)->startOfWeek()->toDateString();
        $this->to = $this->to ?: $today->addWeeks(2)->startOfWeek()->addDays(2)->toDateString();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bookingEvent(): ?array
    {
        $call = $this->call ? $this->workspace()->crewCalls()->get($this->call) : null;
        $event = $call ? $this->workspace()->event($call['event_id']) : null;

        return $event && auth()->user()->can('event.manage-crew', [$event]) ? $event : null;
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $roles = $workspace->people()->pluck('job_title')->unique()->sort()->values();
        $results = collect();
        $valid = $this->from !== '' && $this->to !== '' && $this->from <= $this->to;

        if ($valid && $this->role !== '') {
            $results = $workspace->availablePeople(
                $this->role,
                DateRange::of($this->from, $this->to),
                $this->skill !== '' ? (int) $this->skill : null,
                $this->level,
                $this->country !== '' ? $this->country : null,
            );
        }

        return view('livewire.resourcing.availability', [
            'roles' => $roles,
            'skills' => $workspace->skills()->sortBy('name'),
            'countries' => $workspace->people()->pluck('location_country')->unique()->sort(),
            'results' => $results,
            'valid' => $valid,
            'showRates' => auth()->user()->can('rates.view'),
            'bookingFor' => $this->bookingEvent(),
        ]);
    }
}
