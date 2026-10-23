@php
    use App\Support\DateRange;
    $range = $valid ? DateRange::of($from, $to)->format() : null;
@endphp
<div>
    <x-page-head title="Availability" :answer="$valid ? $results->count().' '.\Illuminate\Support\Str::plural('person', $results->count()).' free for '.$role.' on '.$range.', best match first.' : 'Choose a role and dates to see who is free.'" />

    <form class="card mb-3" wire:submit.prevent>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-sm-6 col-lg-3">
                    <label for="av-role" class="form-label">Role</label>
                    <select id="av-role" class="form-select" wire:model.live="role">
                        @foreach ($roles as $r)
                            <option value="{{ $r }}">{{ $r }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label for="av-from" class="form-label">From</label>
                    <input id="av-from" type="date" @class(['form-control', 'is-invalid' => ! $valid]) wire:model.live="from">
                </div>
                <div class="col-6 col-lg-2">
                    <label for="av-to" class="form-label">To</label>
                    <input id="av-to" type="date" @class(['form-control', 'is-invalid' => ! $valid]) wire:model.live="to">
                    @unless ($valid)
                        <div class="invalid-feedback">The end date must be on or after the start.</div>
                    @endunless
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="av-skill" class="form-label">Skill</label>
                    <select id="av-skill" class="form-select" wire:model.live="skill">
                        <option value="">Any</option>
                        @foreach ($skills as $s)
                            <option value="{{ $s['id'] }}">{{ $s['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-lg-1">
                    <label for="av-level" class="form-label">Level</label>
                    <select id="av-level" class="form-select" wire:model.live="level" @disabled($skill === '')>
                        @foreach ([0 => 'Any', 2 => '2+', 3 => '3+', 4 => '4+', 5 => '5'] as $v => $l)
                            <option value="{{ $v }}">{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-lg-1">
                    <label for="av-country" class="form-label">Based in</label>
                    <select id="av-country" class="form-select" wire:model.live="country">
                        <option value="">Anywhere</option>
                        @foreach ($countries as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </form>

    <div class="table-wrap" wire:loading.class="opacity-50">
        @if ($results->isEmpty())
            <x-empty title="Nobody free with that role and skill">Try a lower skill level, a different date range or anywhere in the world. People booked, on leave or marked unavailable on any of these days are left out.</x-empty>
        @else
            <table class="table">
                <thead><tr><th>Person</th><th>Based in</th><th>Skill level</th><th class="num">Rating</th>@if ($showRates)<th class="num">Day rate</th>@endif<th>Type</th>@if ($bookingFor)<th><span class="visually-hidden">Book</span></th>@endif</tr></thead>
                <tbody>
                    @foreach ($results as $row)
                        @php($p = $row['person'])
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <x-avatar :name="$p['first_name'].' '.$p['last_name']" :id="$p['id']" size="sm" />
                                    <a href="{{ route('people.show', $p['id']) }}" wire:navigate>{{ $p['first_name'] }} {{ $p['last_name'] }}</a>
                                </div>
                            </td>
                            <td>{{ $p['location_city'] }}, {{ $p['location_country'] }}</td>
                            <td>{{ $row['skill_level'] ? $row['skill_level'].' of 5' : '—' }}</td>
                            <td class="num">{{ $row['rating'] ?? '—' }}</td>
                            @if ($showRates)
                                <td class="num">{{ $row['rate']?->format(false) ?? '—' }}</td>
                            @endif
                            <td>{{ $p['employment_type'] === 'freelancer' ? 'Freelance' : 'Staff' }}</td>
                            @if ($bookingFor)
                                <td class="text-end"><a href="{{ route('events.show', ['code' => $bookingFor['code'], 'tab' => 'crew', 'call' => $call, 'person' => $p['id']]) }}" class="btn btn-secondary btn-sm" wire:navigate>Book on {{ $bookingFor['code'] }}</a></td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
