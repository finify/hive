@props(['label', 'value', 'caption' => null, 'href' => null, 'money' => false])
@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class(['figure', 'is-link' => $href]) }}>
    <div class="figure-label">{{ $label }}</div>
    <div @class(['figure-value', 'money' => $money])>{{ $value }}</div>
    @if ($caption)
        <div class="figure-caption">{{ $caption }}</div>
    @endif
</{{ $tag }}>
