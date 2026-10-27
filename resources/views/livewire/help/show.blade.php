<div>
    <x-page-head :title="$guide['title']" :answer="$guide['summary']">
        <x-slot:crumbs>
            <a href="{{ route('help') }}" wire:navigate>Help</a> <span aria-hidden="true">/</span> {{ $guide['group'] }}
        </x-slot:crumbs>
        @if ($open)
            <x-slot:actions>
                <a href="{{ route($open) }}" class="btn btn-secondary" wire:navigate>Open {{ $guide['title'] }}</a>
            </x-slot:actions>
        @endif
    </x-page-head>

    <div class="grid-12">
        <article class="card span-8 help-body">
            <div class="card-body">{!! $body !!}</div>
        </article>
        @if ($related->isNotEmpty())
            <nav class="span-4 align-self-start" aria-label="More in {{ $guide['group'] }}">
                <h2 class="help-group-title">More in {{ $guide['group'] }}</h2>
                <ul class="list-unstyled d-grid gap-2">
                    @foreach ($related as $key => $g)
                        <li><a href="{{ route('help.show', $key) }}" wire:navigate>{{ $g['title'] }}</a><div class="small text-muted">{{ $g['summary'] }}</div></li>
                    @endforeach
                </ul>
                <a href="{{ route('help') }}" class="small" wire:navigate>All guides</a>
            </nav>
        @endif
    </div>
</div>
