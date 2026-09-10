@props(['title', 'code' => null])

<div {{ $attributes->class('panel relative overflow-hidden p-8 md:p-12') }}>
    <div class="grid-bg absolute inset-0 -z-0 opacity-60"></div>
    <div class="relative">
        @if ($code)<p class="font-mono mb-4 text-2xs text-zinc-600">{{ $code }}</p>@endif
        <h3 class="display text-3xl font-semibold text-white md:text-4xl">{{ $title }}</h3>
        <div class="mt-3 max-w-lg text-zinc-400">{{ $slot }}</div>
        @isset($actions)
            <div class="mt-6 flex flex-wrap gap-2">{{ $actions }}</div>
        @endisset
    </div>
</div>
