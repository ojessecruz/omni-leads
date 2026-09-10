@props(['href' => null, 'live' => true])

<a href="{{ $href ?? route('home') }}" {{ $attributes->class('font-mono flex items-center gap-2 text-sm tracking-tight') }}>
    <span class="inline-block size-2 rounded-full bg-signal {{ $live ? 'pulse' : '' }}"></span>
    <span class="text-zinc-400">omni</span><span class="text-white">/leads</span>
</a>
