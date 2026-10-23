@php
    use App\Support\Display;
    $workspace = app(\App\Demo\Workspace::class);
    $expired = $documents->filter(fn ($d) => $d['status']->value === 'expired')->count();
@endphp
<div>
    <x-page-head title="Documents" :answer="$show === 'attention' ? $documents->count().' documents expire within 30 days or have expired, '.$expired.' already expired. Reminders go out 30 and 7 days before.' : $documents->count().' documents on file.'">
        <x-slot:actions>
            <div class="btn-group" role="group" aria-label="Which documents">
                <button type="button" @class(['btn btn-secondary', 'active' => $show === 'attention']) wire:click="$set('show', 'attention')">Needs attention</button>
                <button type="button" @class(['btn btn-secondary', 'active' => $show === 'all']) wire:click="$set('show', 'all')">All</button>
            </div>
        </x-slot:actions>
    </x-page-head>
    <div class="table-wrap">
        @if ($documents->isEmpty())
            <x-empty title="Nothing expiring">Insurance, right to work records and certificates that expire within 30 days appear here.</x-empty>
        @else
            <table class="table">
                <thead><tr><th>Person</th><th>Document</th><th>Expires</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($documents->take(200) as $doc)
                        <tr>
                            <td><a href="{{ route('people.show', $doc['person_id']) }}" wire:navigate>{{ $workspace->personName($doc['person_id']) }}</a></td>
                            <td>{{ $detail ? $doc['title'] : ucfirst(str_replace('-', ' ', $doc['type'])) }}@if ($doc['mandatory'])<span class="small text-muted d-block">Mandatory</span>@endif</td>
                            <td class="tnum">{{ Display::date($doc['expires_at']) }}</td>
                            <td><x-status :value="$doc['status']" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
