@props([
    'title',
    'description',
    'eyebrow' => null,
])

<div class="flex w-full flex-col gap-3">
    @if ($eyebrow)
        <p class="eyebrow">{{ $eyebrow }}</p>
    @endif
    <h1 class="display text-4xl font-semibold text-white">{{ $title }}</h1>
    <p class="text-sm text-zinc-400">{{ $description }}</p>
</div>
