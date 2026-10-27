@php
    use App\Support\Display;
    $user = auth()->user();
@endphp
<div>
    <x-page-head title="Onboarding and offboarding" :answer="$checklists->count().' '.($show === 'open' ? 'open' : 'finished').' '.($checklists->count() === 1 ? 'checklist' : 'checklists').($mine ? ', '.$mine.' '.($mine === 1 ? 'item' : 'items').' waiting for you' : '').'. Each item has an owner and a due date from the start or leaving date.'" />

    <nav class="tabs" aria-label="Checklists">
        <button type="button" @class(['active' => $show === 'open']) wire:click="$set('show', 'open')">Open</button>
        <button type="button" @class(['active' => $show === 'done']) wire:click="$set('show', 'done')">Finished</button>
    </nav>

    <div class="grid-12">
        <div @class(['d-grid gap-3 align-content-start', 'span-8' => $canStart, 'span-12' => ! $canStart])>
            @forelse ($checklists as $c)
                @php
                    $done = $c->items->whereNotNull('completed_at')->count();
                    $person = $c->person;
                @endphp
                <section class="card" wire:key="cl-{{ $c->id }}">
                    <div class="card-header flex-wrap">
                        <x-avatar :name="$person->first_name.' '.$person->last_name" :id="$person->id" size="sm" />
                        <div class="flex-grow-1">
                            <h2 class="card-title"><a href="{{ route('people.show', $person->id) }}" wire:navigate>{{ $person->first_name }} {{ $person->last_name }}</a></h2>
                            <div class="small text-muted">{{ $c->kind->label() }}, {{ $person->employment_type->label() }} · {{ $c->kind->value === 'onboarding' ? 'starts' : 'leaves' }} {{ Display::date($c->anchor_date->toDateString()) }}</div>
                        </div>
                        <div class="text-end small tnum" style="min-width: 8rem">
                            {{ $done }} of {{ $c->items->count() }} done
                            <div class="budget-bar mt-1" role="img" aria-label="{{ $c->progress() }}% done"><span class="seg-actual" style="width: {{ $c->progress() }}%"></span></div>
                        </div>
                    </div>
                    <ul class="list-rows">
                        @foreach ($c->items as $item)
                            @php
                                $canTick = $item->owner->canTick($user, $person);
                                $overdue = $item->completed_at === null && $item->due_date->toDateString() < $today;
                            @endphp
                            <li wire:key="cli-{{ $item->id }}">
                                <input type="checkbox" class="form-check-input mt-0" id="cli-{{ $item->id }}" @checked($item->completed_at) @disabled(! $canTick)
                                       wire:click="tick({{ $item->id }}, {{ $item->completed_at ? 'false' : 'true' }})" aria-describedby="cli-{{ $item->id }}-sub">
                                <div class="row-main">
                                    <label for="cli-{{ $item->id }}" @class(['row-title', 'text-muted text-decoration-line-through' => $item->completed_at])>{{ $item->title }}</label>
                                    <div class="row-sub" id="cli-{{ $item->id }}-sub">{{ $item->owner->label() }}{{ $item->owner->value === 'line-manager' && $person->line_manager_id ? ' ('.app(\App\Demo\Workspace::class)->personName($person->line_manager_id).')' : '' }}</div>
                                </div>
                                @if ($item->completed_at)
                                    <span class="small text-muted tnum">Done {{ Display::date($item->completed_at->toDateString()) }}</span>
                                @else
                                    <span @class(['small tnum', 'text-negative' => $overdue, 'text-muted' => ! $overdue])>{{ $overdue ? 'Overdue, was due' : 'Due' }} {{ Display::date($item->due_date->toDateString()) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <div class="card"><x-empty :title="$show === 'open' ? 'No open checklists' : 'Nothing finished yet'">Start a checklist when someone joins or hands in their notice. Items come from the template for their employment type.</x-empty></div>
            @endforelse
        </div>

        @if ($canStart)
            <section class="card span-4 align-self-start">
                <div class="card-header"><h2 class="card-title">Start a checklist</h2></div>
                <form class="card-body row g-3" wire:submit="start" novalidate>
                    <div class="col-12">
                        <label for="st-person" class="form-label">Person</label>
                        <select id="st-person" @class(['form-select', 'is-invalid' => $errors->has('startForm.person_id')]) wire:model="startForm.person_id">
                            <option value="">Choose a person</option>
                            @foreach ($people as $p)
                                <option value="{{ $p->id }}">{{ $p->first_name }} {{ $p->last_name }}</option>
                            @endforeach
                        </select>
                        @error('startForm.person_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <fieldset class="col-12">
                        <legend class="form-label fs-6">Checklist</legend>
                        <div class="form-check"><input class="form-check-input" type="radio" id="st-on" value="onboarding" wire:model="startForm.kind"><label class="form-check-label" for="st-on">Onboarding, from their start date</label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" id="st-off" value="offboarding" wire:model="startForm.kind"><label class="form-check-label" for="st-off">Offboarding, from their leaving date</label></div>
                    </fieldset>
                    <div class="col-12"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Start checklist</button></div>
                    <div class="col-12 small text-muted">The line manager is told when items are theirs. Set a leaving date on the person’s profile before offboarding.</div>
                </form>
            </section>
        @endif
    </div>
</div>
