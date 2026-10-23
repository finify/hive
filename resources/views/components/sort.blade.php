@props(['field', 'sort', 'direction'])
<button type="button" class="sort-link btn btn-link p-0 text-decoration-none" wire:click="sortBy('{{ $field }}')" aria-label="Sort by {{ trim(strip_tags($slot)) }}">
    {{ $slot }}
    @if ($sort === $field)
        <i class="bi bi-arrow-{{ $direction === 'asc' ? 'up' : 'down' }}" aria-hidden="true"></i>
    @endif
</button>
