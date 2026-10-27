<div>
    <x-page-head title="Help" answer="A guide to every part of Hive you can use: what it is for, how to do the common jobs, and the rules behind them. The ? next to any page title opens its guide." />

    <div class="mb-3" style="max-width: 28rem">
        <label for="help-search" class="visually-hidden">Search the guides</label>
        <input id="help-search" type="search" class="form-control" placeholder="Search the guides, for example “TOIL” or “purchase order”" wire:model.live.debounce.300ms="search" autocomplete="off">
    </div>

    @forelse ($groups as $group => $guides)
        <section class="mb-4" wire:key="hg-{{ $group }}">
            <h2 class="help-group-title">{{ $group }}</h2>
            <div class="help-grid">
                @foreach ($guides as $g)
                    <a href="{{ route('help.show', $g['key']) }}" class="card help-card text-reset text-decoration-none" wire:navigate wire:key="h-{{ $g['key'] }}">
                        <div class="card-body">
                            <h3 class="card-title mb-1">{{ $g['title'] }}</h3>
                            <p class="small text-muted mb-0">{{ $g['summary'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <div class="card"><x-empty title="No guide mentions that">Try another word, or ask Ask Hive.</x-empty></div>
    @endforelse
</div>
