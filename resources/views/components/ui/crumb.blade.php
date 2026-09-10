@props(['href' => null])

@if ($href)
    <a href="{{ $href }}" wire:navigate {{ $attributes->class('truncate hover:text-white') }}>{{ $slot }}</a>
    <span class="text-zinc-700">/</span>
@else
    <span {{ $attributes->class('truncate text-zinc-300') }}>{{ $slot }}</span>
@endif
