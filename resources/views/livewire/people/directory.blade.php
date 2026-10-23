@php
    $workspace = app(\App\Demo\Workspace::class);
    $skillName = fn ($id) => $workspace->skills()->get($id)['name'] ?? '';
@endphp
<div>
    <x-page-head title="Directory" :answer="$people->total() === $total ? $total.' people, staff and freelance.' : $people->total().' of '.$total.' people match.'">
        <x-slot:actions>
            <div class="btn-group" role="group" aria-label="Layout">
                <button type="button" @class(['btn btn-secondary', 'active' => $layout === 'cards']) wire:click="$set('layout', 'cards')" aria-label="Card view"><i class="bi bi-grid" aria-hidden="true"></i></button>
                <button type="button" @class(['btn btn-secondary', 'active' => $layout === 'table']) wire:click="$set('layout', 'table')" aria-label="Table view"><i class="bi bi-list-ul" aria-hidden="true"></i></button>
            </div>
        </x-slot:actions>
    </x-page-head>

    <div class="filters" role="search">
        <label class="visually-hidden" for="pp-search">Search people</label>
        <input id="pp-search" type="search" class="form-control is-search" placeholder="Search name, title or email" wire:model.live.debounce.300ms="search">
        <label class="visually-hidden" for="pp-dept">Department</label>
        <select id="pp-dept" class="form-select" wire:model.live="department">
            <option value="">All departments</option>
            @foreach ($departments as $d)
                <option value="{{ $d['id'] }}">{{ $d['name'] }}</option>
            @endforeach
        </select>
        <label class="visually-hidden" for="pp-type">Type</label>
        <select id="pp-type" class="form-select" wire:model.live="type">
            <option value="">Staff and freelance</option>
            <option value="employee">Employees</option>
            <option value="freelancer">Freelancers</option>
        </select>
        <label class="visually-hidden" for="pp-skill">Skill</label>
        <select id="pp-skill" class="form-select" wire:model.live="skill">
            <option value="">Any skill</option>
            @foreach ($skills as $s)
                <option value="{{ $s['id'] }}">{{ $s['name'] }}</option>
            @endforeach
        </select>
        <label class="visually-hidden" for="pp-loc">Location</label>
        <select id="pp-loc" class="form-select" wire:model.live="location">
            <option value="">Anywhere</option>
            @foreach ($locations as $l)
                <option value="{{ $l }}">{{ $l }}</option>
            @endforeach
        </select>
    </div>

    @if ($people->isEmpty())
        <div class="card"><x-empty title="Nobody matches">Try a different skill or location. Everyone who works with the agency, staff and freelance, is listed here.</x-empty></div>
    @elseif ($layout === 'cards')
        <div class="persona-grid" wire:loading.class="opacity-50">
            @foreach ($people as $p)
                <a href="{{ route('people.show', $p['id']) }}" class="persona text-decoration-none" wire:navigate wire:key="pc-{{ $p['id'] }}">
                    <span class="persona-top">
                        <x-avatar :name="$p['first_name'].' '.$p['last_name']" :id="$p['id']" />
                        <span class="lh-sm">
                            <span class="persona-name d-block">{{ $p['preferred_name'] ?: $p['first_name'] }} {{ $p['last_name'] }}</span>
                            <span class="persona-role d-block">{{ $p['job_title'] }}, {{ $p['employment_type'] === 'freelancer' ? 'freelance' : $workspace->departmentName($p['department_id']) }}</span>
                        </span>
                    </span>
                    <span class="persona-line">{{ $p['location_city'] }} <span class="ms-1">{{ $p['email'] }}</span></span>
                    @if ($p['skills'])
                        <span class="d-flex flex-wrap gap-1">
                            @foreach (array_slice($p['skills'], 0, 3) as $s)
                                <x-pill>{{ $skillName($s['skill_id']) }} {{ $s['level'] }}</x-pill>
                            @endforeach
                        </span>
                    @endif
                </a>
            @endforeach
        </div>
    @else
        <div class="table-wrap" wire:loading.class="opacity-50">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th><x-sort field="name" :sort="$sort" :direction="$direction">Name</x-sort></th>
                        <th><x-sort field="title" :sort="$sort" :direction="$direction">Job title</x-sort></th>
                        <th><x-sort field="department" :sort="$sort" :direction="$direction">Department</x-sort></th>
                        <th>Type</th>
                        <th><x-sort field="location" :sort="$sort" :direction="$direction">Location</x-sort></th>
                        <th>Work email</th>
                        <th>Skills</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($people as $p)
                        <tr wire:key="pr-{{ $p['id'] }}">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <x-avatar :name="$p['first_name'].' '.$p['last_name']" :id="$p['id']" size="sm" />
                                    <a href="{{ route('people.show', $p['id']) }}" wire:navigate>{{ $p['preferred_name'] ?: $p['first_name'] }} {{ $p['last_name'] }}</a>
                                </div>
                            </td>
                            <td>{{ $p['job_title'] }}</td>
                            <td>{{ $workspace->departmentName($p['department_id']) }}</td>
                            <td>{{ \App\Enums\EmploymentType::from($p['employment_type'])->label() }}</td>
                            <td>{{ $p['location_city'] }}</td>
                            <td>{{ $p['email'] }}</td>
                            <td class="small">{{ collect($p['skills'])->map(fn ($s) => $skillName($s['skill_id']))->take(2)->implode(', ') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <div class="mt-3">{{ $people->links() }}</div>
</div>
