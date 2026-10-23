@php
    use App\Support\DateRange;
    use App\Support\Display;
    $workspace = app(\App\Demo\Workspace::class);
@endphp
<div>
    <x-page-head title="Payroll locks" :answer="$workspace->lockedUntil() ? 'Time is locked up to '.Display::date($workspace->lockedUntil()).'. Locked time can’t be added, changed or returned.' : 'No time is locked yet.'" />

    <div class="grid-12">
        <section class="card span-8">
            <div class="card-header"><h2 class="card-title">Periods</h2></div>
            @if ($locks->isEmpty())
                <x-empty title="Nothing locked yet">Lock a month after payroll has run, so approved time can’t change underneath it.</x-empty>
            @else
                <ul class="list-rows">
                    @foreach ($locks as $lock)
                        <li wire:key="lock-{{ $lock->id }}">
                            <i @class(['bi', 'bi-lock-fill' => ! $lock->unlocked_at, 'bi-unlock text-muted' => $lock->unlocked_at]) aria-hidden="true"></i>
                            <div class="row-main">
                                <div class="row-title tnum">{{ DateRange::of($lock->period_start->toDateString(), $lock->period_end->toDateString())->format() }}</div>
                                <div class="row-sub">
                                    Locked by {{ $workspace->personName($lock->locked_by) }}, {{ Display::dateTime($lock->locked_at) }}
                                    @if ($lock->unlocked_at)
                                        <span class="d-block">Unlocked by {{ $workspace->personName($lock->unlocked_by) }}, {{ Display::dateTime($lock->unlocked_at) }}: {{ $lock->reason }}</span>
                                    @endif
                                </div>
                            </div>
                            @if ($lock->unlocked_at)
                                <x-pill tone="neutral">Unlocked</x-pill>
                            @else
                                <x-pill tone="positive" icon="lock">Locked</x-pill>
                                <button type="button" class="btn btn-quiet btn-sm" wire:click="startUnlock({{ $lock->id }})">Unlock</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card span-4">
            <div class="card-header"><h2 class="card-title">Lock a period</h2></div>
            <form class="card-body d-grid gap-3" wire:submit="lock" novalidate>
                <div>
                    <label for="pl-start" class="form-label">From</label>
                    <input id="pl-start" type="date" @class(['form-control', 'is-invalid' => $errors->has('period_start')]) wire:model="periodStart">
                    @error('period_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label for="pl-end" class="form-label">To</label>
                    <input id="pl-end" type="date" @class(['form-control', 'is-invalid' => $errors->has('period_end')]) wire:model="periodEnd">
                    @error('period_end')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:confirm="Lock all time in this period? People won’t be able to add or change it.">Lock period</button>
                <p class="small text-muted mb-0">Run payroll first. Fully approved weeks in the period read Locked; anything still waiting can be approved but not changed.</p>
            </form>
        </section>
    </div>

    <x-modal id="unlock-modal" title="Unlock a period">
        <label for="unlock-reason" class="form-label">Why does it need unlocking?</label>
        <textarea id="unlock-reason" rows="3" @class(['form-control', 'is-invalid' => $errors->has('reason')]) wire:model="unlockReason" placeholder="For example: Kofi’s 23 Sep entry was missing a day; payroll will run an adjustment"></textarea>
        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <p class="small text-muted mt-2 mb-0">Unlocking is recorded in the audit log with your reason. Lock the period again once the correction is approved.</p>
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" wire:click="unlock" wire:loading.attr="disabled">Unlock</button>
        </x-slot:footer>
    </x-modal>
</div>
