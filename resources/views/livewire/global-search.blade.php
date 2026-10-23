<div class="search-box" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false; $refs.input.blur()">
    <i class="bi bi-search" aria-hidden="true"></i>
    <label for="global-search" class="visually-hidden">Search events, people, clients and POs</label>
    <input id="global-search" x-ref="input" type="search" class="form-control" placeholder="Search events, people, clients, POs" autocomplete="off"
           wire:model.live.debounce.250ms="query" @focus="open = true" @input="open = true" role="combobox" aria-expanded="false" :aria-expanded="open.toString()" aria-controls="search-results">
    <kbd class="d-none d-md-inline" aria-hidden="true">/</kbd>
    @if (mb_strlen(trim($query)) >= 2)
        <div class="search-results" id="search-results" x-show="open" x-cloak role="listbox">
            @forelse ($results as $group => $items)
                <div class="result-group">{{ $group }}</div>
                @foreach ($items as $item)
                    <a href="{{ $item['href'] }}" class="result" wire:navigate role="option">
                        <span class="flex-grow-1 text-truncate">{{ $item['label'] }}</span>
                        <span class="small text-muted text-truncate">{{ $item['meta'] }}</span>
                    </a>
                @endforeach
            @empty
                <div class="p-2 text-muted small">Nothing matches “{{ $query }}”. Try an event code like EV-26114, a name or a PO number.</div>
            @endforelse
        </div>
    @endif
</div>
