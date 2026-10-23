@props(['value'])
{{-- A status pill for any enum implementing HasTone: text always, colour as a second signal. --}}
@if ($value instanceof \App\Enums\HasTone)
    <x-pill :tone="$value->tone()" {{ $attributes }}>{{ $value->label() }}</x-pill>
@else
    <span class="text-muted">—</span>
@endif
