@props(['name', 'id' => 0, 'size' => null])
<span {{ $attributes->class(['avatar', 'is-'.$size => $size]) }} style="--hue: {{ \App\Support\Display::hue((int) $id) }}" aria-hidden="true">{{ \App\Support\Display::initials($name) }}</span>
