@php
    use App\Enums\EntryStatus;
    use App\Enums\TimesheetStatus;
    use App\Support\Display;
    $workspace = app(\App\Demo\Workspace::class);
    $status = $sheet ? TimesheetStatus::from($sheet['status']) : null;
    $hours = $sheet ? collect($sheet['entries'])->sum('hours') : 0;
@endphp
<div>
    <x-page-head title="My timesheets" :answer="'Week of '.$weekStart->format('j M Y').': '.($sheet ? Display::hours($hours).', '.mb_strtolower($status->label()).'.' : 'nothing recorded yet.')">
        <x-slot:actions>
            <div class="btn-group" role="group" aria-label="Change week">
                <button type="button" class="btn btn-secondary" wire:click="shiftWeek(-1)" aria-label="Previous week"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-secondary" wire:click="shiftWeek(1)" aria-label="Next week"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            </div>
            @if ($sheet && in_array($sheet['status'], ['draft', 'returned'], true))
                <button type="button" class="btn btn-primary" wire:click="submit({{ $sheet['id'] }})" wire:loading.attr="disabled">Submit timesheet</button>
            @endif
        </x-slot:actions>
    </x-page-head>

    @if ($locked)
        <div class="callout mb-3"><i class="bi bi-lock" aria-hidden="true"></i><div>This week is locked after payroll. Finance can unlock it if something needs correcting.</div></div>
    @endif

    <div class="grid-12">
        <section class="card span-8">
            <div class="card-header">
                <h2 class="card-title">{{ $weekStart->format('j M') }} to {{ $weekStart->addDays(6)->format('j M Y') }}</h2>
                @if ($status)
                    <x-status :value="$status" class="ms-auto" />
                @endif
            </div>
            @if (! $sheet || $sheet['entries'] === [])
                <x-empty title="No time this week">Add each day you worked below: the event or internal code, your start and finish, and your break. Submit once the week is complete.</x-empty>
            @else
                <ul class="list-rows">
                    @foreach (collect($sheet['entries'])->sortBy('date') as $entry)
                        @php($event = $workspace->event($entry['event_id']))
                        <li class="flex-wrap">
                            <div style="min-width: 7.5rem" class="tnum">{{ Display::date($entry['date'], true) }}</div>
                            <div class="row-main">
                                <div class="row-title">{{ $event ? $event['code'].' '.$event['name'] : $entry['internal_code'] }}</div>
                                <div class="row-sub tnum">{{ $entry['start_time'] }}–{{ $entry['end_time'] }}, {{ $entry['break_minutes'] }} min break, {{ Display::hours($entry['hours']) }}</div>
                                @foreach ($flags[$entry['id']] ?? [] as $flag)
                                    <div class="flag"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>{{ $flag['message'] }}</div>
                                @endforeach
                                @if ($entry['return_comment'])
                                    <div class="note small mt-1">Returned: {{ $entry['return_comment'] }}</div>
                                @endif
                            </div>
                            @if ($sheet['status'] !== 'draft')
                                <x-status :value="EntryStatus::from($entry['status'])" />
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card span-4">
            <div class="card-header"><h2 class="card-title">Add time</h2></div>
            <div class="card-body">
                @if (! $editable)
                    <p class="text-muted mb-0">This week has been submitted. If something is missing, ask your approver to return it.</p>
                @else
                    <form wire:submit="addEntry" class="d-grid gap-3">
                        <div>
                            <label for="ts-date" class="form-label">Date</label>
                            <input id="ts-date" type="date" @class(['form-control', 'is-invalid' => $errors->has('date')]) wire:model="date">
                            @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="ts-work" class="form-label">Worked on</label>
                            <select id="ts-work" @class(['form-select', 'is-invalid' => $errors->has('work')]) wire:model="work">
                                <option value="">Choose…</option>
                                <optgroup label="Events you are booked on">
                                    @foreach ($bookedEvents as $e)
                                        <option value="event:{{ $e['id'] }}">{{ $e['code'] }} {{ $e['name'] }}</option>
                                    @endforeach
                                </optgroup>
                                <optgroup label="Internal">
                                    @foreach ($codes as $c)
                                        <option value="{{ $c }}">{{ $c }}</option>
                                    @endforeach
                                </optgroup>
                            </select>
                            @error('work')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="row g-2">
                            <div class="col-4">
                                <label for="ts-start" class="form-label">Start</label>
                                <input id="ts-start" type="time" @class(['form-control tnum', 'is-invalid' => $errors->has('start_time')]) wire:model="start_time">
                            </div>
                            <div class="col-4">
                                <label for="ts-end" class="form-label">Finish</label>
                                <input id="ts-end" type="time" @class(['form-control tnum', 'is-invalid' => $errors->has('end_time')]) wire:model="end_time">
                            </div>
                            <div class="col-4">
                                <label for="ts-break" class="form-label">Break (min)</label>
                                <input id="ts-break" type="number" min="0" step="5" class="form-control tnum" wire:model="break_minutes">
                            </div>
                            @error('end_time')<div class="col-12 small text-negative">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="ts-notes" class="form-label">Notes (optional)</label>
                            <input id="ts-notes" class="form-control" wire:model="notes" placeholder="For example: stayed to reset for day 2">
                        </div>
                        <button type="submit" class="btn btn-secondary" wire:loading.attr="disabled">Add entry</button>
                    </form>
                @endif
            </div>
        </section>

        <section class="card span-12">
            <div class="card-header"><h2 class="card-title">Recent weeks</h2></div>
            @if ($history->isEmpty())
                <x-empty title="No timesheets yet">Each week you record time appears here with its status.</x-empty>
            @else
                <ul class="list-rows">
                    @foreach ($history as $h)
                        <li>
                            <div class="row-main">
                                <button type="button" class="btn btn-link link p-0" wire:click="$set('week', '{{ $h['week_start'] }}')">Week of {{ Display::date($h['week_start']) }}</button>
                                <div class="row-sub">{{ count($h['entries']) }} {{ count($h['entries']) === 1 ? 'entry' : 'entries' }}, {{ Display::hours(collect($h['entries'])->sum('hours')) }}</div>
                            </div>
                            <x-status :value="TimesheetStatus::from($h['status'])" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
