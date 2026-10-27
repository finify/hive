<a href="{{ route('dashboard') }}" class="brand" wire:navigate>
    <x-brand-mark />
    <span>
        <span class="brand-name d-block">Hive</span>
        <span class="brand-org d-block">Lumen &amp; Co Live</span>
    </span>
</a>

<nav>
    @foreach (\App\Support\Navigation::for(auth()->user()) as $group)
        <div class="nav-group">
            <div class="nav-group-title">{{ $group['label'] }}</div>
            @foreach ($group['items'] as $item)
                @php($active = $item['planned'] ? request()->route('module') === $item['params']['module'] : request()->routeIs($item['active']))
                <a href="{{ route($item['route'], $item['params']) }}"
                   @class(['nav-item-link', 'active' => $active, 'is-planned' => $item['planned']])
                   @if ($active) aria-current="page" @endif
                   @if ($item['tip']) data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="{{ $item['tip'] }}" @endif
                   wire:navigate>
                    <i class="bi bi-{{ $item['icon'] }}" aria-hidden="true"></i>
                    <span>{{ $item['label'] }}</span>
                    @if ($item['planned'])
                        <span class="count">Later</span>
                    @endif
                </a>
            @endforeach
        </div>
    @endforeach
</nav>
