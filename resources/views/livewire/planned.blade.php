<div>
    <x-page-head :title="$info['title']" />
    <div class="card mw-form">
        <x-empty :title="'Arrives in build phase '.$info['phase']" action="Back to the dashboard" :href="route('dashboard')">
            {{ $info['summary'] }}
        </x-empty>
    </div>
</div>
