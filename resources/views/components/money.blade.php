@props(['amount', 'short' => false, 'bold' => false])
@if ($amount instanceof \App\Support\Money)
    <span {{ $attributes->class(['tnum', 'money' => $bold, 'text-negative' => $amount->isNegative()]) }}>{{ $short ? $amount->formatShort() : $amount->format() }}</span>
@else
    <span class="text-muted">—</span>
@endif
