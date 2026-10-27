<div>
    <x-page-head title="Import from CSV" answer="Add people, clients, suppliers or venues from a spreadsheet. Every row is checked with the same rules as the add form before anything is saved." />

    <div class="grid-12">
        <section class="card span-4 align-self-start">
            <form class="card-body row g-3" wire:submit="check">
                <div class="col-12">
                    <label for="imp-type" class="form-label">What</label>
                    <select id="imp-type" class="form-select" wire:model.live="type">
                        @foreach ($types as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label for="imp-file" class="form-label">CSV file</label>
                    <input id="imp-file" type="file" accept=".csv,text/csv" @class(['form-control', 'is-invalid' => $errors->has('file')]) wire:model="file">
                    @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Up to {{ \App\Actions\Integrations\ImportCsv::MAX_ROWS }} rows, with a header row. <a href="{{ route('import.template', $type) }}">Download the template</a>.</div>
                </div>
                <div class="col-12"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="check, file">Check the file</button></div>
                <div class="col-12 small text-muted">Columns: <span class="code">{{ implode(', ', $columns) }}</span></div>
            </form>
        </section>

        <section class="span-8">
            @if ($checked)
                <div @class(['callout mb-3', 'is-negative' => $problems, 'is-positive' => ! $problems])>
                    <i class="bi bi-{{ $problems ? 'exclamation-octagon' : 'check2-circle' }}" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        @if ($problems)
                            {{ $problems }} of {{ count($checked) }} rows have problems. Fix them in the spreadsheet and check the file again; nothing has been saved.
                        @else
                            All {{ count($checked) }} rows are ready.
                        @endif
                    </div>
                    @unless ($problems)
                        <button type="button" class="btn btn-primary btn-sm" wire:click="import" wire:loading.attr="disabled" wire:confirm="Import {{ count($checked) }} rows?">Import {{ count($checked) }} rows</button>
                    @endunless
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <caption class="visually-hidden">Rows checked</caption>
                        <thead><tr><th class="num">Line</th><th>Row</th><th>Check</th></tr></thead>
                        <tbody>
                            @foreach ($checked as $r)
                                <tr wire:key="line-{{ $r['line'] }}">
                                    <td class="num">{{ $r['line'] }}</td>
                                    <td>{{ collect($r['row'])->filter()->take(4)->implode(', ') }}</td>
                                    <td>
                                        @if ($r['errors'])
                                            <ul class="mb-0 ps-3 small text-negative">@foreach ($r['errors'] as $err)<li>{{ $err }}</li>@endforeach</ul>
                                        @else
                                            <span class="small text-positive">Ready</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="card"><x-empty title="Nothing checked yet">Choose what you’re importing and upload the file. You’ll see every row with any problems before anything is saved.</x-empty></div>
            @endif
        </section>
    </div>
</div>
