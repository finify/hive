<!DOCTYPE html>
<html lang="en-GB" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} – Hive</title>
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
    {{ $slot }}
    @livewireScripts
</body>
</html>
