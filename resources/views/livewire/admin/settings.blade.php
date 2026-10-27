@php
    use App\Support\Settings as HiveSettings;
@endphp
<div>
    <x-page-head title="Settings" :answer="$canManage ? 'Limits and thresholds Hive works to. Changes apply at once and are logged.' : 'Limits and thresholds Hive works to. Only an admin can change them.'" />

    <form wire:submit="save" class="d-grid gap-3 mw-form">
        @foreach ($groups as $group => $fields)
            <fieldset class="card" @disabled(! $canManage)>
                <legend class="card-header card-title fs-6 m-0 w-100">{{ $group }}</legend>
                <div class="card-body row g-3">
                    @foreach ($fields as $key => $f)
                        @php($k = HiveSettings::formKey($key))
                        <div @class(['col-sm-6' => $f['type'] !== 'bool', 'col-12' => $f['type'] === 'bool'])>
                            @if ($f['type'] === 'bool')
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="s-{{ $k }}" wire:model="values.{{ $k }}">
                                    <label class="form-check-label" for="s-{{ $k }}">{{ $f['label'] }}</label>
                                </div>
                            @else
                                <label for="s-{{ $k }}" class="form-label">{{ $f['label'] }}</label>
                                @if ($f['type'] === 'select')
                                    <select id="s-{{ $k }}" class="form-select" wire:model="values.{{ $k }}">
                                        @foreach ($f['options'] as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <div @class(['input-group' => $f['type'] === 'money'])>
                                        @if ($f['type'] === 'money')<span class="input-group-text">£</span>@endif
                                        <input id="s-{{ $k }}" @class(['form-control tnum', 'is-invalid' => $errors->has('values.'.$k)]) inputmode="decimal" wire:model="values.{{ $k }}">
                                    </div>
                                @endif
                                @error('values.'.$k)<div class="small text-negative mt-1">{{ $message }}</div>@enderror
                                @if (isset($f['hint']))<div class="form-text">{{ $f['hint'] }}</div>@endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
        @if ($canManage)
            <div><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Save settings</button></div>
        @endif
        <p class="small text-muted">Credentials for Microsoft sign-in, Xero and Claude are set in the server’s environment, not here. Departments, skills, leave types and checklist templates are seeded data for now.</p>
    </form>
</div>
