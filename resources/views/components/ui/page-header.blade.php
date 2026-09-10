@props([
    'eyebrow' => null,
    'title',
    'accent' => null,
    'description' => null,
    'crumbs' => null,
])

<div {{ $attributes->class(['mb-10' => ! preg_match('/\bmb-/', $attributes->get('class', '')), 'flex flex-wrap items-end justify-between gap-6']) }} data-reveal>
    <div class="min-w-0">
        @if ($crumbs)
            <nav class="font-mono mb-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-2xs uppercase tracking-label text-zinc-500">
                {{ $crumbs }}
            </nav>
        @elseif ($eyebrow)
            <p class="eyebrow mb-4">{{ $eyebrow }}</p>
        @endif
        <h1 class="display text-4xl font-semibold text-white md:text-6xl">
            {{ $title }}
            @if ($accent)
                <span class="serif-it text-zinc-400">{{ $accent }}</span>
            @endif
        </h1>
        @if ($description)
            <p class="mt-4 max-w-2xl text-zinc-400">{{ $description }}</p>
        @endif
    </div>
    @if (trim($slot))
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</div>
