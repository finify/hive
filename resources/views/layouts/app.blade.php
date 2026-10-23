<!DOCTYPE html>
<html lang="en-GB" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' – Hive' : 'Hive' }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <script>
        (() => {
            let choice = null;
            try { choice = localStorage.getItem('hive-theme'); } catch (e) {}
            const dark = choice === 'dark' || ((!choice || choice === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
        })();
    </script>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <a href="#main" class="visually-hidden-focusable btn btn-primary position-absolute m-2" style="z-index: 2000">Skip to content</a>

    @if (config('hive.demo.enabled'))
        @include('layouts.partials.demo-band')
    @endif

    <div class="app-shell">
        <aside class="sidebar" aria-label="Main navigation">
            @include('layouts.partials.nav')
        </aside>

        <div class="d-flex flex-column" style="min-width: 0">
            @include('layouts.partials.topbar')

            <main id="main" class="page" tabindex="-1">
                {{ $slot }}
            </main>

            <footer class="px-4 pb-4 small text-muted d-flex flex-wrap gap-3">
                <span>Hive for {{ app(\App\Demo\DemoData::class)->raw('organisation')['name'] }}</span>
                <a href="{{ route('privacy') }}" wire:navigate>Privacy notice</a>
                <span>All companies, people and figures are fictional.</span>
            </footer>
        </div>
    </div>

    <div class="offcanvas offcanvas-start" tabindex="-1" id="mobile-nav" aria-labelledby="mobile-nav-title">
        <div class="offcanvas-header">
            <h2 class="visually-hidden" id="mobile-nav-title">Navigation</h2>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="offcanvas" aria-label="Close navigation"></button>
        </div>
        <div class="offcanvas-body pt-0">
            @include('layouts.partials.nav')
        </div>
    </div>

    @can('ai.use')
        @if (config('hive.ai.enabled'))
            <livewire:ask-hive />
        @endif
    @endcan

    <div class="toast-stack" id="toast-stack" aria-live="polite"></div>

    @if (session('toast'))
        <script>
            document.addEventListener('DOMContentLoaded', () => window.dispatchEvent(new CustomEvent('toast', { detail: @json(session('toast')) })));
        </script>
    @endif

    @livewireScripts
</body>
</html>
