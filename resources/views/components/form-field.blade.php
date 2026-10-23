@props(['name', 'label', 'type' => 'text', 'model' => null, 'col' => 'col-sm-6', 'hint' => null, 'live' => false])
@php($key = $model ?? 'form.'.$name)
<div class="{{ $col }}">
    <label for="ff-{{ str_replace('.', '-', $key) }}" class="form-label">{{ $label }}</label>
    @if ($type === 'textarea')
        <textarea id="ff-{{ str_replace('.', '-', $key) }}" rows="2" @class(['form-control', 'is-invalid' => $errors->has($key)]) wire:model{{ $live ? '.live' : '' }}="{{ $key }}" {{ $attributes }}></textarea>
    @else
        <input id="ff-{{ str_replace('.', '-', $key) }}" type="{{ $type }}" @class(['form-control', 'is-invalid' => $errors->has($key)]) wire:model{{ $live ? '.live' : '' }}="{{ $key }}" {{ $attributes }}>
    @endif
    @error($key)<div class="invalid-feedback">{{ $message }}</div>@enderror
    @if ($hint)<div class="form-text">{{ $hint }}</div>@endif
</div>
