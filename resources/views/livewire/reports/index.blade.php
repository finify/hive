<div>
    <x-page-head title="Reports" :answer="count($reports).' reports you can run. Each one filters and downloads as CSV, showing only what your role may see.'" />

    @if ($reports === [])
        <div class="card"><x-empty title="No reports for your role">Reports cover money, people and time; ask your Hive administrator if you need one.</x-empty></div>
    @else
        <div class="grid-12">
            @foreach ($reports as $r)
                <a href="{{ route('reports.show', $r::key()) }}" class="card span-6 report-card text-reset text-decoration-none" wire:navigate wire:key="r-{{ $r::key() }}">
                    <div class="card-body">
                        <h2 class="card-title mb-1">{{ $r->title() }}</h2>
                        <p class="text-muted small mb-0">{{ $r->description() }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
