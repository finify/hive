@props(['financials'])
{{-- Actual, committed and remaining estimate against the budget (the vertical line). --}}
@php
    $f = $financials;
    $scale = max($f->budget->minor, $f->forecast()->minor, 1);
    $pct = fn (int $minor) => round(max(0, $minor) * 100 / $scale, 2);
@endphp
<div class="budget-bar" role="img" aria-label="Actual {{ $f->actual->format(false) }}, committed {{ $f->committed->format(false) }}, remaining {{ $f->remaining->format(false) }} against a budget of {{ $f->budget->format(false) }}">
    <span class="seg-actual" style="width: {{ $pct($f->actual->minor) }}%"></span>
    <span class="seg-committed" style="width: {{ $pct($f->committed->minor) }}%"></span>
    <span class="seg-remaining" style="width: {{ $pct($f->remaining->minor) }}%"></span>
    <span class="budget-line" style="left: calc({{ $pct($f->budget->minor) }}% - 1px)"></span>
</div>
