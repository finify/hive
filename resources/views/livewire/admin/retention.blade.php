@php
    use App\Support\Display;
    $labels = ['timesheet' => 'Timesheet', 'expense' => 'Expense', 'leaver' => 'Leaver', 'document' => 'Document', 'ai-request' => 'Ask Hive log'];
@endphp
<div>
    <x-page-head title="Data retention" :answer="$open->count().' records are past their retention period. On the first of each month Hive flags more; nothing is deleted until someone decides here.'" />

    <div class="table-wrap mb-3">
        @if ($open->isEmpty())
            <x-empty title="Nothing to review">Timesheets and expenses are kept {{ $retention['timesheets_years'] }} years, leavers’ records {{ $retention['leavers_years'] }} years after they leave, expired documents {{ $retention['expired_documents_years'] }} years and the Ask Hive log {{ $retention['ai_requests_months'] }} months. Change these in Settings.</x-empty>
        @else
            <table class="table">
                <caption class="visually-hidden">Records past retention</caption>
                <thead><tr><th>Record</th><th>Kind</th><th>Past retention since</th><th><span class="visually-hidden">Decide</span></th></tr></thead>
                <tbody>
                    @foreach ($open as $f)
                        <tr wire:key="rf-{{ $f->id }}">
                            <td>{{ $f->label }}</td>
                            <td>{{ $labels[$f->category] ?? $f->category }}</td>
                            <td class="tnum">{{ Display::date($f->past_retention_on) }}</td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-quiet btn-sm" wire:click="decide({{ $f->id }}, false)">Keep</button>
                                <button type="button" class="btn btn-secondary btn-sm text-negative" wire:click="decide({{ $f->id }}, true)" wire:confirm="{{ $f->category === 'leaver' ? 'Anonymise this person? Their financial records stay.' : 'Delete this record for good?' }}">{{ $f->category === 'leaver' ? 'Anonymise' : 'Delete' }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($decided->isNotEmpty())
        <section class="card">
            <div class="card-header"><h2 class="card-title">Recent decisions</h2></div>
            <ul class="list-rows">
                @foreach ($decided as $f)
                    <li><div class="row-main">{{ $f->label }}<div class="row-sub">{{ ucfirst($f->decision === 'keep' ? 'kept' : $f->decision) }} {{ Display::dateTime($f->decided_at) }}</div></div></li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
