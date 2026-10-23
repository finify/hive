@props(['tone' => 'neutral', 'icon' => null])
@php($toneValue = $tone instanceof \App\Enums\Tone ? $tone->value : $tone)
<span {{ $attributes->class(['pill', 'is-'.$toneValue => $toneValue !== 'neutral']) }}>@if ($icon)<i class="bi bi-{{ $icon }}" aria-hidden="true"></i>@endif{{ $slot }}</span>
