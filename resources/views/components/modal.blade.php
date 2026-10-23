@props(['id', 'title', 'size' => null])
{{-- Bootstrap modal. Open from Livewire with $this->dispatch('modal', id: '...', show: true). --}}
<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}-title" aria-hidden="true" wire:ignore.self>
    <div @class(['modal-dialog modal-dialog-centered modal-dialog-scrollable', 'modal-'.$size => $size])>
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-6 fw-medium" id="{{ $id }}-title">{{ $title }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">{{ $slot }}</div>
            @isset($footer)
                <div class="modal-footer">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>
