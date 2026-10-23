@props(['title', 'intro' => null])
<x-layouts.guest :title="$title">
    <div class="min-vh-100 d-flex align-items-center justify-content-center p-3">
        <div class="bg-surface border rounded-3 p-4 p-md-5 w-100" style="max-width: 440px">
            <a href="{{ route('login') }}" class="brand p-0 mb-4">
                <x-brand-mark />
                <span>
                    <span class="brand-name d-block">Hive</span>
                    <span class="brand-org d-block">Lumen &amp; Co Live</span>
                </span>
            </a>
            <h1 class="fs-4 fw-medium mb-1" style="letter-spacing: -0.015em">{{ $title }}</h1>
            @if ($intro)
                <p class="text-muted">{{ $intro }}</p>
            @endif
            {{ $slot }}
        </div>
    </div>
</x-layouts.guest>
