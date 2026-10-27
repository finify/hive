@php($unread = app(\App\Demo\Workspace::class)->inboxFor(auth()->user())->where('read', false)->count())
<header class="topbar">
    <button class="icon-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobile-nav" aria-controls="mobile-nav" aria-label="Open navigation">
        <i class="bi bi-list fs-5" aria-hidden="true"></i>
    </button>

    <livewire:global-search />

    <div class="ms-auto d-flex align-items-center gap-1">
        @can('ai.use')
            @if (config('hive.ai.enabled'))
                <button class="btn btn-secondary btn-sm d-inline-flex align-items-center gap-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#ask-hive" aria-controls="ask-hive">
                    <i class="bi bi-chat-square-text" aria-hidden="true"></i><span class="d-none d-sm-inline">Ask Hive</span>
                </button>
            @endif
        @endcan

        <a href="{{ route('notifications') }}" class="icon-btn" wire:navigate aria-label="Notifications{{ $unread ? ', '.$unread.' unread' : '' }}">
            <i class="bi bi-bell" aria-hidden="true"></i>
            @if ($unread)
                <span class="dot">{{ $unread }}</span>
            @endif
        </a>

        <div class="dropdown">
            <button class="btn btn-quiet btn-sm d-flex align-items-center gap-2 px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu for {{ auth()->user()->name }}">
                <x-avatar :name="auth()->user()->name" :id="auth()->user()->person_id ?? auth()->id()" />
                <span class="d-none d-md-inline text-start lh-sm">
                    <span class="d-block fw-medium">{{ auth()->user()->name }}</span>
                    <span class="d-block small text-muted">{{ auth()->user()->primaryRole()?->label() }}</span>
                </span>
            </button>
            <div class="dropdown-menu dropdown-menu-end p-2" style="min-width: 240px">
                @if (auth()->user()->person_id)
                    <a class="dropdown-item rounded" href="{{ route('people.show', auth()->user()->person_id) }}" wire:navigate>My profile</a>
                @endif
                <a class="dropdown-item rounded" href="{{ route('notification-settings') }}" wire:navigate>Notification settings</a>
                <a class="dropdown-item rounded" href="{{ route('security') }}" wire:navigate>Security and two-factor</a>
                <div class="dropdown-divider"></div>
                <div class="px-2 pb-1 small text-muted">Appearance</div>
                <div class="btn-group w-100 px-2 pb-2" role="group" aria-label="Appearance">
                    <button type="button" class="btn btn-secondary btn-sm" data-theme-choice="light">Light</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-theme-choice="dark">Dark</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-theme-choice="system">System</button>
                </div>
                <div class="dropdown-divider"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item rounded">Sign out</button>
                </form>
            </div>
        </div>
    </div>
</header>
