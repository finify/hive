@php
    use App\Enums\BillingStatus;
    use App\Enums\BudgetStatus;
    use App\Support\Display;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
@endphp
<div>
    @if ($draft || $baseline)
        <div @class(['callout mb-3', 'is-warning' => $draft])>
            <i class="bi bi-journal-text" aria-hidden="true"></i>
            <div class="flex-grow-1">
                @if ($draft)
                    Version {{ $draft->version }} is a revision, {{ $againstBaseline?->isNegative() ? $againstBaseline->abs()->format(false).' below' : ($againstBaseline?->format(false) ?? '£0').' above' }} the approved baseline of {{ Money::of($baseline->total_gbp_minor ?? 0)->format(false) }}.
                    @if ($canApprove)
                        <button type="button" class="btn btn-primary btn-sm ms-2" wire:click="approve" wire:loading.attr="disabled" wire:confirm="Approve this revision as the new baseline?">Approve revision</button>
                    @else
                        It needs the operations director’s approval.
                    @endif
                @else
                    Version {{ $baseline->version }}, approved {{ Display::date($baseline->approved_at) }} by {{ $workspace->personName($baseline->approved_by) }}. Changing a line starts a revision.
                @endif
            </div>
        </div>
    @endif

    @if ($financials)
        <div class="figure-row">
            <x-figure label="Budget" :value="$financials->budget->format(false)" :caption="'Version '.$budget['version'].', '.mb_strtolower(BudgetStatus::from($budget['status'])->label())" />
            <x-figure label="Forecast at completion" :value="$financials->forecast()->format(false)" :caption="'Variance '.$financials->variance()->format(false)" />
            <x-figure label="Margin" :value="$financials->margin()->format(false)" :caption="($financials->marginPercent() ?? '—').'% of '.$financials->revenue->format(false)" />
            <x-figure label="Health" :value="$financials->health?->label() ?? 'Final'" :caption="$financials->forecastPercentOfBudget().'% of budget forecast'" />
        </div>

        <section class="card mb-3">
            <div class="card-header flex-wrap">
                <h2 class="card-title">Cost lines</h2>
                @if ($canEdit)
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="startLine">Add a line</button>
                @endif
                <div class="legend ms-auto">
                    <span><span class="swatch" style="background: var(--hive-data)"></span>Actual</span>
                    <span><span class="swatch" style="background: color-mix(in srgb, var(--hive-data) 45%, var(--hive-surface))"></span>Committed</span>
                    <span><span class="swatch" style="background: repeating-linear-gradient(135deg, var(--hive-control) 0 2px, transparent 2px 5px)"></span>Remaining estimate</span>
                    <span><span class="swatch" style="background: var(--hive-ink); width: 2px"></span>Budget</span>
                </div>
            </div>
            <div class="table-wrap border-0 rounded-0">
                <table class="table">
                    <thead>
                        <tr><th>Category</th><th>Line</th><th class="num">Budget</th><th class="num">Committed</th><th class="num">Actual</th><th class="num">Remaining</th><th class="num">Forecast</th><th style="min-width: 140px"><span class="visually-hidden">Bar</span></th>@if ($canEdit || $canRaisePo)<th><span class="visually-hidden">Actions</span></th>@endif</tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $line)
                            @php($over = $line['forecast']->greaterThan($line['budget']))
                            @php($scale = max($line['budget']->minor, $line['forecast']->minor, 1))
                            <tr>
                                <td class="text-nowrap">{{ $workspace->costCategoryName($line['category']) }}</td>
                                <td>
                                    {{ $line['description'] }}
                                    @if ($line['supplier_id'])
                                        <div class="small text-muted">{{ $workspace->suppliers()->get($line['supplier_id'])['name'] }}</div>
                                    @endif
                                    @if ($line['original'])
                                        <div class="small text-muted">{{ $line['original']->format(false) }} at {{ $line['fx_rate'] }} £/€</div>
                                    @endif
                                </td>
                                <td class="num">{{ $line['budget']->format(false) }}</td>
                                <td class="num">{{ $line['committed']->format(false) }}</td>
                                <td class="num">{{ $line['actual']->format(false) }}</td>
                                <td class="num">{{ $line['remaining']->format(false) }}</td>
                                <td @class(['num', 'text-negative fw-medium' => $over])>{{ $line['forecast']->format(false) }}</td>
                                <td>
                                    <div class="budget-bar" role="img" aria-label="Forecast {{ $line['forecast']->format(false) }} against {{ $line['budget']->format(false) }}">
                                        <span class="seg-actual" style="width: {{ round($line['actual']->minor * 100 / $scale, 2) }}%"></span>
                                        <span class="seg-committed" style="width: {{ round($line['committed']->minor * 100 / $scale, 2) }}%"></span>
                                        <span class="seg-remaining" style="width: {{ round($line['remaining']->minor * 100 / $scale, 2) }}%"></span>
                                        <span class="budget-line" style="left: calc({{ round($line['budget']->minor * 100 / $scale, 2) }}% - 1px)"></span>
                                    </div>
                                </td>
                                @if ($canEdit || $canRaisePo)
                                    <td class="text-end text-nowrap">
                                        @if ($canRaisePo)
                                            <a href="{{ route('purchase-orders.create', ['event' => $event['id'], 'line' => $line['id']]) }}" class="btn btn-quiet btn-sm" wire:navigate>Raise PO</a>
                                        @endif
                                        @if ($canEdit)
                                            <button type="button" class="btn btn-quiet btn-sm" wire:click="startLine({{ $line['id'] }})" aria-label="Change {{ $line['description'] }}">Change</button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="fw-medium">
                            <td colspan="2">Total</td>
                            <td class="num">{{ $financials->budget->format(false) }}</td>
                            <td class="num">{{ $financials->committed->format(false) }}</td>
                            <td class="num">{{ $financials->actual->format(false) }}</td>
                            <td class="num">{{ $financials->remaining->format(false) }}</td>
                            <td @class(['num', 'text-negative' => $financials->forecast()->greaterThan($financials->budget)])>{{ $financials->forecast()->format(false) }}</td>
                            <td></td>
                            @if ($canEdit || $canRaisePo)<td></td>@endif
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <div class="grid-12">
            <section class="card span-6">
                <div class="card-header"><h2 class="card-title">Client revenue</h2><span class="small text-muted ms-auto tnum">{{ $financials->revenue->format(false) }}</span></div>
                <ul class="list-rows">
                    @foreach ($revenueLines as $r)
                        <li wire:key="rev-{{ $r['id'] }}">
                            <div class="row-main"><div>{{ $r['description'] }}</div><div class="row-sub">{{ \App\Enums\RevenueType::from($r['type'])->label() }}</div></div>
                            <span class="tnum">{{ Money::of($r['amount_minor'])->format(false) }}</span>
                            @if ($canEdit && $r['type'] !== 'fee')
                                <button type="button" class="btn btn-quiet btn-sm" wire:click="removeRevenue({{ $r['id'] }})" wire:confirm="Remove this revenue?" aria-label="Remove {{ $r['description'] }}">Remove</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($canEdit)
                    <form class="card-body row g-2 border-top border-rule" wire:submit="addRevenue" novalidate>
                        <div class="col-sm-4">
                            <label for="rv-type" class="form-label small">Type</label>
                            <select id="rv-type" class="form-select" wire:model="revenueForm.type">
                                @foreach ($revenueTypes as $t)
                                    <option value="{{ $t->value }}">{{ $t->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-form-field name="description" model="revenueForm.description" label="Description" col="col-sm-5" placeholder="For example Extra breakout room" />
                        <x-form-field name="amount" model="revenueForm.amount" label="Amount" col="col-sm-3" inputmode="decimal" />
                        <div class="col-12"><button type="submit" class="btn btn-secondary btn-sm" wire:loading.attr="disabled">Add revenue</button></div>
                    </form>
                @endif
            </section>
            <section class="card span-6">
                <div class="card-header"><h2 class="card-title">Payment milestones</h2></div>
                @if ($milestones->isNotEmpty())
                    <ul class="list-rows">
                        @foreach ($milestones as $m)
                            <li>
                                <div class="row-main"><div>{{ $m['description'] }}</div><div class="row-sub tnum">Due {{ Display::date($m['due_date']) }}@if ($m['invoice_reference']), {{ $m['invoice_reference'] }}@endif</div></div>
                                <span class="tnum">{{ Money::of($m['amount_minor'])->format(false) }}</span>
                                <x-status :value="BillingStatus::from($m['status'])" />
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty title="No milestones yet">Deposit and payment milestones are added when the contract is signed.</x-empty>
                @endif
            </section>
        </div>
    @else
        <div class="card"><x-empty title="No budget yet">Add the first cost line to start a draft budget. The operations director approves it as the baseline.</x-empty>
            @if ($canEdit)<div class="card-body pt-0 text-center"><button type="button" class="btn btn-primary" wire:click="startLine">Add the first line</button></div>@endif
        </div>
    @endif

    @if ($versions->count() > 1)
        <section class="card mt-3">
            <div class="card-header"><h2 class="card-title">Versions</h2></div>
            <ul class="list-rows">
                @foreach ($versions as $v)
                    <li>
                        <div class="row-main"><div class="row-title">Version {{ $v->version }}</div><div class="row-sub">{{ $v->approved_at ? 'Approved '.Display::date($v->approved_at).' by '.$workspace->personName($v->approved_by) : 'Being revised' }}</div></div>
                        @if ($v->status !== BudgetStatus::Draft)<span class="tnum">{{ Money::of($v->total_gbp_minor)->format(false) }}</span>@endif
                        <x-status :value="$v->status" />
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($canEdit)
        <x-modal id="line-modal" :title="$editingLineId ? 'Change budget line' : 'Add a budget line'">
            <form id="line-form" class="row g-3" wire:submit="saveLine" novalidate>
                <div class="col-sm-6">
                    <label for="bl-cat" class="form-label">Category</label>
                    <select id="bl-cat" class="form-select" wire:model="lineForm.category">
                        @foreach ($categories as $key => $name)
                            <option value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6">
                    <label for="bl-sup" class="form-label">Supplier (optional)</label>
                    <select id="bl-sup" class="form-select" wire:model="lineForm.supplier_id">
                        <option value="">None yet</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-form-field name="description" model="lineForm.description" label="Description" col="col-12" />
                <x-form-field name="amount" model="lineForm.amount" label="Budget" inputmode="decimal" />
                <div class="col-sm-6">
                    <label for="bl-cur" class="form-label">Currency</label>
                    <select id="bl-cur" @class(['form-select', 'is-invalid' => $errors->has('lineForm.currency')]) wire:model="lineForm.currency">
                        @foreach ($currencies as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                    @error('lineForm.currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <x-form-field name="notes" model="lineForm.notes" label="Notes (optional)" type="textarea" col="col-12" />
                @error('line')<div class="col-12 small text-negative">{{ $message }}</div>@enderror
            </form>
            <x-slot:footer>
                @if ($editingLineId)
                    <button type="button" class="btn btn-quiet text-negative me-auto" wire:click="removeLine({{ $editingLineId }})" wire:confirm="Remove this line?">Remove line</button>
                @endif
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="line-form" class="btn btn-primary" wire:loading.attr="disabled">{{ $editingLineId ? 'Save line' : 'Add line' }}</button>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
