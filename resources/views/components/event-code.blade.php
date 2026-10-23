@props(['event'])
<a href="{{ route('events.show', $event['code']) }}" wire:navigate {{ $attributes->class('code link') }}>{{ $event['code'] }}</a>
