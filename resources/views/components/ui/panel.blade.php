@props([
    'title' => null,
    'eyebrow' => null,
    'padding' => true,
])

<section {{ $attributes->class(['panel overflow-hidden']) }}>
    @if ($title || $eyebrow || isset($actions))
        <div class="panel-head">
            <div class="min-w-0">
                @if ($eyebrow)<span class="eyebrow">{{ $eyebrow }}</span>@endif
                @if ($title)<h2 class="truncate text-sm font-medium text-white {{ $eyebrow ? 'mt-0.5' : '' }}">{{ $title }}</h2>@endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div @class(['p-5' => $padding])>{{ $slot }}</div>
</section>
