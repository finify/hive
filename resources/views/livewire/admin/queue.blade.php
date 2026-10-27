<div>
    <x-page-head title="Queue" :answer="($waiting !== null ? $waiting.' jobs waiting. ' : '').($failed->isEmpty() ? 'No failed jobs.' : $failed->count().' failed '.($failed->count() === 1 ? 'job' : 'jobs').'. Retry once the cause is fixed; see the runbook.')" />

    <div class="table-wrap">
        @if ($failed->isEmpty())
            <x-empty title="Nothing has failed">Ask Hive answers, webhooks, reminders and digests run in the background. Anything that fails after its retries appears here.</x-empty>
        @else
            <table class="table">
                <caption class="visually-hidden">Failed jobs</caption>
                <thead><tr><th>Failed</th><th>Job</th><th>Error</th>@if ($canManage)<th><span class="visually-hidden">Actions</span></th>@endif</tr></thead>
                <tbody>
                    @foreach ($failed as $f)
                        <tr wire:key="fj-{{ $f['uuid'] }}">
                            <td class="tnum text-nowrap">{{ \App\Support\Display::dateTime($f['failed_at']) }}</td>
                            <td class="code">{{ $f['job'] }}</td>
                            <td class="small text-break">{{ \Illuminate\Support\Str::limit($f['error'], 180) }}</td>
                            @if ($canManage)
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-secondary btn-sm" wire:click="retry('{{ $f['uuid'] }}')">Retry</button>
                                    <button type="button" class="btn btn-quiet btn-sm" wire:click="forget('{{ $f['uuid'] }}')" wire:confirm="Clear this failed job?">Clear</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
