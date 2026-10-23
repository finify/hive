@props(['title', 'action' => null, 'href' => null])
<div {{ $attributes->class('empty-state') }}>
    <div class="empty-title">{{ $title }}</div>
    <div class="empty-body">{{ $slot }}</div>
    @if ($action && $href)
        <a href="{{ $href }}" class="btn btn-secondary btn-sm" wire:navigate>{{ $action }}</a>
    @endif
</div>
