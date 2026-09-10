@props(['label'])

<div {{ $attributes }}>
    <dt class="eyebrow tracking-label">{{ $label }}</dt>
    <dd class="mt-1 text-sm text-zinc-200">{{ $slot }}</dd>
</div>
