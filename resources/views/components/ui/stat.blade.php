@props(['label', 'value', 'href' => null, 'hint' => null, 'signal' => false])

@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class('group block p-6') }}>
    <p class="eyebrow">{{ $label }}</p>
    <p class="font-mono mt-4 text-4xl tracking-tight {{ $signal ? 'text-signal' : 'text-white' }} md:text-5xl">{{ $value }}</p>
    @if ($hint)
        <p class="font-mono mt-3 text-2xs text-zinc-600 group-hover:text-zinc-400">{{ $hint }}</p>
    @endif
</{{ $tag }}>
