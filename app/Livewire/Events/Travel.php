<?php

namespace App\Livewire\Events;

use App\Actions\Travel\SaveTravelBooking;
use App\Demo\Rows;
use App\Enums\TravelKind;
use App\Enums\TravelStatus;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\TravelBooking;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The event's Travel tab: everyone's journeys and hotels, grouped by
 * person, with the manifest export. Costs show only with budget access.
 */
class Travel extends Component
{
    use UsesWorkspace;

    public int $eventId;

    public ?int $editing = null;

    /** @var array<string, string> */
    public array $travelForm = [];

    public function mount(int $eventId): void
    {
        $this->eventId = $eventId;
        $this->resetForm();
    }

    public function startBooking(?int $personId = null, string $kind = 'outbound'): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->travelForm['person_id'] = $personId ? (string) $personId : '';
        $this->travelForm['kind'] = $kind;
        $this->travelForm['mode'] = TravelKind::from($kind)->modes()[0]->value;
        $this->dispatch('modal', id: 'travel-modal', show: true);
    }

    public function edit(int $bookingId): void
    {
        $b = TravelBooking::query()->where('event_id', $this->eventId)->findOrFail($bookingId);
        $this->resetValidation();
        $this->editing = $b->id;
        $this->travelForm = [
            'person_id' => (string) $b->person_id, 'kind' => $b->kind->value, 'mode' => $b->mode->value,
            'from_place' => (string) $b->from_place, 'to_place' => (string) $b->to_place,
            'depart_at' => (string) $b->depart_at?->format('Y-m-d\TH:i'), 'arrive_at' => (string) $b->arrive_at?->format('Y-m-d\TH:i'),
            'check_in' => (string) $b->check_in?->toDateString(), 'check_out' => (string) $b->check_out?->toDateString(),
            'provider' => (string) $b->provider, 'reference' => (string) $b->reference,
            'cost' => $b->cost_minor ? sprintf('%d.%02d', intdiv($b->cost_minor, 100), $b->cost_minor % 100) : '', 'currency' => $b->currency, 'notes' => (string) $b->notes,
        ];
        $this->dispatch('modal', id: 'travel-modal', show: true);
    }

    public function updatedTravelFormKind(string $kind): void
    {
        $modes = TravelKind::tryFrom($kind)?->modes() ?? [];
        $this->travelForm['mode'] = $modes[0]->value ?? '';
    }

    public function save(SaveTravelBooking $save): void
    {
        $this->resetValidation();

        try {
            $save->handle(auth()->user(), $this->eventId, array_map(fn ($v) => $v === '' ? null : $v, $this->travelForm), $this->editing);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['travelForm.'.$k => $m])->all());

            return;
        }

        $this->dispatch('modal', id: 'travel-modal', show: false);
        $this->toast($this->editing ? 'Travel changed. The traveller has been told.' : 'Travel added. The traveller has been told.');
        $this->resetForm();
    }

    public function cancelBooking(int $bookingId, SaveTravelBooking $save): void
    {
        $save->cancel(auth()->user(), $bookingId);
        $this->toast('Travel cancelled; its cost has been reversed.');
    }

    private function resetForm(): void
    {
        $this->editing = null;
        $this->travelForm = [
            'person_id' => '', 'kind' => 'outbound', 'mode' => 'flight', 'from_place' => '', 'to_place' => '', 'depart_at' => '', 'arrive_at' => '',
            'check_in' => '', 'check_out' => '', 'provider' => '', 'reference' => '', 'cost' => '', 'currency' => 'GBP', 'notes' => '',
        ];
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $event = $workspace->event($this->eventId) ?? abort(404);
        $canManage = Gate::allows('event.manage-crew', [$event]);
        $showCosts = $this->viewer()->canSeeBudget($event);

        // Hotels sort at a 15:00 check-in, after the morning's flight.
        $bookings = TravelBooking::query()->where('event_id', $this->eventId)->get()
            ->sortBy(fn (TravelBooking $b) => $b->depart_at?->format('Y-m-d H:i') ?? $b->check_in?->format('Y-m-d').' 15:00')
            ->groupBy('person_id');

        $people = $workspace->assignments()->where('event_id', $this->eventId)
            ->filter(fn ($a) => ! in_array($a['status'], ['released', 'declined'], true))->pluck('person_id')
            ->merge(Rows::of($event['team'])->pluck('person_id'))->push($event['producer_id'])
            ->filter()->unique()
            ->mapWithKeys(fn ($id) => [$id => $workspace->personName($id)])
            ->sort();

        $live = TravelBooking::query()->where('event_id', $this->eventId)->where('status', TravelStatus::Booked)->get();

        return view('livewire.events.travel', [
            'event' => $event,
            'bookings' => $bookings,
            'people' => $people,
            'canManage' => $canManage,
            'showCosts' => $showCosts,
            'travellers' => $live->pluck('person_id')->unique()->count(),
            'nights' => (int) $live->where('kind', TravelKind::Accommodation)->sum(fn ($b) => $b->check_in && $b->check_out ? $b->check_in->diffInDays($b->check_out) : 0),
            'totals' => $live->groupBy('currency')->map(fn ($g, $cur) => Money::of((int) $g->sum('cost_minor'), (string) $cur)->format(false))->values()->implode(' + '),
            'currencies' => (array) config('hive.finance.currencies'),
        ]);
    }
}
