@props(['title', 'answer' => null])
<div class="page-head">
    <div>
        @isset($crumbs)
            <div class="crumbs">{{ $crumbs }}</div>
        @endisset
        <h1 class="page-title">{{ $title }}</h1>
        @if ($answer)
            <p class="page-answer">{{ $answer }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="page-actions">{{ $actions }}</div>
    @endisset
</div>
