@php
    use App\Support\DateRange;
    $workspace = app(\App\Demo\Workspace::class);
@endphp
<div>
    <x-modal id="resolve-modal" title="Resolve clash" size="lg">
        @if (! $clash)
            <p class="mb-0 text-muted">This clash has already been resolved.</p>
        @else
            @php($name = $workspace->personName($clash->personId))
            <p>{{ $name }} is booked twice on {{ $clash->dates->format() }}:</p>
            <ul class="list-unstyled d-grid gap-2 mb-3">
                @foreach ([$clash->first, $clash->second] as $b)
                    @php($ev = $workspace->event($b->eventId))
                    <li class="d-flex gap-2 align-items-center">
                        @if ($ev)
                            <span class="code">{{ $ev['code'] }}</span> <span>{{ $ev['name'] }}</span> <span class="text-muted">{{ $ev['city'] }}, producer {{ $workspace->personName($ev['producer_id']) }}</span>
                        @else
                            <span>{{ $b->label }}</span> <span class="text-muted tnum">{{ $b->dates->format() }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($movable === [])
                <div class="callout is-warning"><i class="bi bi-lock" aria-hidden="true"></i><div>Only the producers of these events or the operations director can move these bookings.</div></div>
            @else
                <div class="btn-group mb-3" role="group" aria-label="How to resolve">
                    <button type="button" @class(['btn btn-secondary btn-sm', 'active' => $mode === 'move']) wire:click="$set('mode', 'move')">Move the days to someone else</button>
                    <button type="button" @class(['btn btn-secondary btn-sm', 'active' => $mode === 'keep']) wire:click="$set('mode', 'keep')">Keep both bookings</button>
                </div>

                <fieldset class="mb-3">
                    <legend class="form-label fs-6">Which booking changes?</legend>
                    @foreach ($movable as $a)
                        @php($ev = $workspace->event($a['event_id']))
                        <div class="form-check">
                            <input class="form-check-input" type="radio" id="mv-{{ $a['id'] }}" value="{{ $a['id'] }}" wire:model.live="assignmentId">
                            <label class="form-check-label" for="mv-{{ $a['id'] }}">{{ $ev['code'] }} {{ $ev['name'] }}, booked {{ DateRange::of($a['start_date'], $a['end_date'])->format() }}</label>
                        </div>
                    @endforeach
                </fieldset>

                @if ($mode === 'move')
                    <fieldset>
                        <legend class="form-label fs-6">Who takes over {{ $clash->dates->format() }}?</legend>
                        @php($skillText = $call && $call['skill_id'] ? ', '.$workspace->skills()->get($call['skill_id'])['name'].' level '.$call['min_skill_level'].' or above' : '')
                        <p class="small text-muted">{{ ($call['role_name'] ?? 'Same role').$skillText }}, free on those dates. Nearest and best match first.</p>
                        @forelse ($suggestions as $s)
                            <label @class(['d-flex align-items-center gap-2 border rounded-3 p-2 mb-2', 'border-dark' => $replacementId === $s['person']['id']]) for="rp-{{ $s['person']['id'] }}">
                                <input class="form-check-input mt-0" type="radio" id="rp-{{ $s['person']['id'] }}" value="{{ $s['person']['id'] }}" wire:model.live="replacementId">
                                <x-avatar :name="$s['person']['first_name'].' '.$s['person']['last_name']" :id="$s['person']['id']" size="sm" />
                                <span class="flex-grow-1">
                                    <span class="d-block fw-medium">{{ $s['person']['first_name'] }} {{ $s['person']['last_name'] }}</span>
                                    <span class="d-block small text-muted">{{ collect([$s['person']['location_city'], $s['skill_level'] ? 'skill level '.$s['skill_level'] : null, $s['rating'] ? 'rated '.$s['rating'].' of 5' : null])->filter()->implode(', ') }}</span>
                                </span>
                                @if ($showRates && $s['rate'])
                                    <span class="tnum small">{{ $s['rate']->format(false) }} a day</span>
                                @endif
                            </label>
                        @empty
                            <p class="text-muted">Nobody with this role and skill is free on those dates. Keep both bookings with a reason, or widen the search on the availability page.</p>
                        @endforelse
                        @error('replacementId')
                            <div class="text-negative small">{{ $message }}</div>
                        @enderror
                    </fieldset>
                @else
                    <label for="keep-reason" class="form-label">Why can both bookings stand?</label>
                    <textarea id="keep-reason" rows="2" @class(['form-control', 'is-invalid' => $errors->has('reason')]) wire:model="reason" placeholder="For example: covers the morning build only, then travels for the evening show"></textarea>
                    @error('reason')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                @endif
            @endif
        @endif

        <x-slot:footer>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Not now</button>
            @if ($clash && $movable !== [])
                @if ($mode === 'move')
                    @php($chosen = $replacementId ? $workspace->personName($replacementId) : null)
                    <button type="button" class="btn btn-primary" wire:click="resolve" wire:loading.attr="disabled" @disabled(! $replacementId)>
                        {{ $chosen ? 'Move '.$clash->dates->format().' to '.$chosen : 'Choose who takes over' }}
                    </button>
                @else
                    <button type="button" class="btn btn-primary" wire:click="keepBoth" wire:loading.attr="disabled">Keep both and record why</button>
                @endif
            @endif
        </x-slot:footer>
    </x-modal>
</div>
