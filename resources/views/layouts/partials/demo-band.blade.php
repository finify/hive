@php
    $personas = app(\App\Demo\DemoData::class)->raw('personas');
    $changes = app(\App\Demo\Workspace::class)->ledger->count();
    $today = app(\App\Demo\DemoData::class)->today();
@endphp
<div class="demo-band" role="region" aria-label="Demo controls">
    <span><strong class="fw-medium">Demo</strong><span class="demo-hint"> with fictional data, kept current to</span> {{ $today->format('D j M Y') }}</span>

    <div class="dropdown">
        <button class="btn btn-link dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Switch persona</button>
        <div class="dropdown-menu p-2" style="min-width: 300px">
            @foreach ($personas as $persona)
                <form method="POST" action="{{ route('demo.switch') }}">
                    @csrf
                    <input type="hidden" name="email" value="{{ $persona['email'] }}">
                    <button type="submit" @class(['dropdown-item rounded d-flex align-items-center gap-2', 'active' => auth()->user()->email === $persona['email']])>
                        <x-avatar :name="$persona['name']" :id="$persona['person_id']" size="sm" />
                        <span class="lh-sm">
                            <span class="d-block">{{ $persona['name'] }}</span>
                            <span class="d-block small text-muted">{{ \App\Enums\Role::from($persona['roles'][0])->label() }}</span>
                        </span>
                    </button>
                </form>
            @endforeach
        </div>
    </div>

    @if ($changes > 0)
        <form method="POST" action="{{ route('demo.reset') }}" class="ms-md-auto" onsubmit="return confirm('Undo the {{ $changes }} demo changes made in this session?')">
            @csrf
            <button type="submit" class="btn btn-link">Reset demo ({{ $changes }} {{ $changes === 1 ? 'change' : 'changes' }})</button>
        </form>
    @else
        <span class="ms-md-auto opacity-75 demo-hint">Events and bookings reset every hour; other changes last until you sign out</span>
    @endif
</div>
