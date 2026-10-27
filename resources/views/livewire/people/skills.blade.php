<div>
    <x-page-head title="Skills" :answer="$total.' skills in the catalogue, levels 1 to 5. A tick means someone has verified the level; change levels on a person’s profile.'" />

    <div class="mb-3" style="max-width: 24rem">
        <label for="skill-search" class="visually-hidden">Search skills or people</label>
        <input id="skill-search" type="search" class="form-control" placeholder="Search skills or people" wire:model.live.debounce.300ms="search">
    </div>

    @forelse ($groups as $category => $skills)
        <section class="card mb-3" wire:key="cat-{{ $category }}">
            <div class="card-header"><h2 class="card-title">{{ ucfirst(str_replace('-', ' ', $category)) }}</h2></div>
            <ul class="list-rows">
                @foreach ($skills as $skill)
                    <li class="flex-wrap align-items-start" wire:key="sk-{{ $skill['id'] }}">
                        <div class="row-main" style="min-width: 12rem">
                            <div class="row-title">{{ $skill['name'] }}</div>
                            <div class="row-sub">{{ $skill['holders']->count() }} {{ $skill['holders']->count() === 1 ? 'person' : 'people' }}{{ $skill['holders']->count() ? ', '.$skill['holders']->where('verified', true)->count().' verified' : '' }}</div>
                        </div>
                        <div class="d-flex flex-wrap gap-1 flex-grow-1">
                            @foreach ($skill['holders'] as $h)
                                <a href="{{ route('people.show', $h['id']) }}" class="pill text-decoration-none" wire:navigate>
                                    {{ $h['name'] }} <span class="tnum">{{ $h['level'] }}</span>@if ($h['verified'])<i class="bi bi-check2 ms-1" aria-label="verified"></i>@endif
                                </a>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <div class="card"><x-empty title="No skills match">Try another word, or a person’s name.</x-empty></div>
    @endforelse
</div>
