@props(['status'])
@if ($status)
    <x-status :value="$status" />
@else
    <span class="text-muted">—</span>
@endif
