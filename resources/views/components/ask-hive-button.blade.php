@props(['workflow', 'subject' => null, 'size' => 'sm'])
@php
    $flow = $workflow instanceof \App\Services\AskHive\Workflow ? $workflow : \App\Services\AskHive\Workflow::from($workflow);
    $show = config('hive.ai.enabled') && auth()->user()?->can('ai.use')
        && $flow->allows(app(\App\Demo\Viewer::class), app(\App\Demo\Workspace::class), $subject);
@endphp
@if ($show)
    <button type="button" {{ $attributes->class(['btn btn-secondary d-inline-flex align-items-center gap-2', 'btn-sm' => $size === 'sm']) }}
            x-data x-on:click="$dispatch('ask-hive-open'); Livewire.dispatch('ask-hive-workflow', { workflow: @js($flow->value), subject: @js($subject) })">
        <i class="bi bi-chat-square-text" aria-hidden="true"></i>{{ $flow->label() }}
    </button>
@endif
