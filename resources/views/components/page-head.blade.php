@props(['title', 'answer' => null, 'help' => null])
@php
    // The guide for this page: given, or found from the current route.
    $helpKey = $help ?? (request()->routeIs('help*') ? null : \App\Support\Help::forRoute(request()->route()?->getName()));
    $helpGuide = $helpKey && auth()->check() ? (\App\Support\Help::for(auth()->user())[$helpKey] ?? null) : null;
@endphp
<div class="page-head">
    <div>
        @isset($crumbs)
            <div class="crumbs">{{ $crumbs }}</div>
        @endisset
        <h1 class="page-title">
            {{ $title }}
            @if ($helpGuide)
                <a href="{{ route('help.show', $helpKey) }}" class="help-link" wire:navigate
                   data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-title="{{ $helpGuide['summary'] }} Select for the full guide."
                   aria-label="Help: {{ $helpGuide['title'] }}"><i class="bi bi-question-circle" aria-hidden="true"></i></a>
            @endif
        </h1>
        @if ($answer)
            <p class="page-answer">{{ $answer }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="page-actions">{{ $actions }}</div>
    @endisset
</div>
