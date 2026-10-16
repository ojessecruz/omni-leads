{{--
    Filter dropdown bound to a Livewire property: themed panel, optional search (on by
    default past 8 options), per-option counts and keyboard navigation.
    options: [value => ['label' => string, 'count' => ?int, 'hint' => ?string]]; allLabel adds an empty-value
    "All" entry on top. Re-keyed when the options change, since x-data reads them once.
--}}
@props([
    'model',
    'options' => [],
    'allLabel' => null,
    'placeholder' => null,
    'searchable' => null,
    'icon' => null,
])

@php
    $items = collect($options)->map(fn ($o, $v) => ['value' => (string) $v, 'label' => (string) $o['label'], 'count' => $o['count'] ?? null, 'hint' => $o['hint'] ?? null])->values();
    if ($allLabel !== null) {
        $items->prepend(['value' => '', 'label' => $allLabel, 'count' => null, 'hint' => null]);
    }
    $searchable ??= $items->count() > 8;
@endphp

<div
    wire:key="combobox-{{ $model }}-{{ md5($items->toJson()) }}"
    x-data="{
        open: false,
        q: '',
        active: 0,
        value: $wire.entangle('{{ $model }}').live,
        items: @js($items),
        norm: (s) => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(),
        get filtered() { const q = this.norm(this.q.trim()); return q ? this.items.filter(i => this.norm(i.label + ' ' + (i.hint ?? '')).includes(q)) : this.items },
        get current() { return this.items.find(i => i.value === String(this.value ?? '')) },
        toggle() { this.open ? this.close() : this.show() },
        show() { this.open = true; this.q = ''; this.active = Math.max(0, this.filtered.findIndex(i => i.value === String(this.value ?? ''))); this.$nextTick(() => (this.$refs.search ?? this.$refs.list).focus()) },
        close() { this.open = false; this.$refs.button.focus() },
        choose(item) { if (item) this.value = item.value; this.close() },
        move(step) { const n = this.filtered.length; if (n) { this.active = (this.active + step + n) % n; this.$nextTick(() => this.$refs.list.querySelector('[data-active]')?.scrollIntoView({ block: 'nearest' })) } },
    }"
    x-on:keydown.escape.prevent.stop="open && close()"
    x-on:click.outside="open = false"
    {{ $attributes->class('relative') }}
>
    <button
        type="button"
        x-ref="button"
        x-on:click="toggle()"
        x-on:keydown.down.prevent="show()"
        :aria-expanded="open"
        aria-haspopup="listbox"
        class="flex h-10 w-full items-center gap-2 rounded-lg border border-white/10 bg-white/3 px-3 text-left text-sm transition hover:border-white/20 focus:outline-none focus-visible:border-signal/60"
        :class="value ? 'text-white' : 'text-zinc-400'"
    >
        @if ($icon)<flux:icon :name="$icon" variant="micro" class="shrink-0 text-zinc-500" />@endif
        <span class="min-w-0 flex-1 truncate" x-text="current?.label ?? @js($placeholder ?? $allLabel ?? '')"></span>
        <template x-if="value && current?.count != null"><span class="font-mono text-2xs text-zinc-500" x-text="current.count.toLocaleString(document.documentElement.lang)"></span></template>
        <flux:icon.chevron-down variant="micro" class="shrink-0 text-zinc-500 transition" ::class="open && 'rotate-180'" />
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="absolute left-0 z-40 mt-1 w-full min-w-72 overflow-hidden rounded-xl border border-white/10 bg-panel shadow-glow"
    >
        @if ($searchable)
            <div class="border-b border-white/8 p-2">
                <input
                    x-ref="search"
                    x-model="q"
                    x-on:input="active = 0"
                    x-on:keydown.down.prevent="move(1)"
                    x-on:keydown.up.prevent="move(-1)"
                    x-on:keydown.enter.prevent="choose(filtered[active])"
                    type="text"
                    placeholder="{{ __('Search…') }}"
                    class="w-full rounded-md bg-white/5 px-2.5 py-1.5 text-sm text-white placeholder-zinc-500 outline-none focus:ring-1 focus:ring-signal/50"
                    autocomplete="off"
                />
            </div>
        @endif
        <ul
            x-ref="list"
            role="listbox"
            tabindex="-1"
            x-on:keydown.down.prevent="move(1)"
            x-on:keydown.up.prevent="move(-1)"
            x-on:keydown.enter.prevent="choose(filtered[active])"
            class="max-h-72 overflow-y-auto p-1 outline-none"
        >
            <template x-for="(item, index) in filtered" :key="item.value">
                <li
                    role="option"
                    :aria-selected="item.value === String(value ?? '')"
                    :data-active="index === active ? '' : null"
                    x-on:click="choose(item)"
                    x-on:mousemove="active = index"
                    class="flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-1.5 text-sm"
                    :class="[index === active ? 'bg-white/8 text-white' : 'text-zinc-300', item.count === 0 ? 'opacity-50' : '']"
                >
                    <span class="w-4 shrink-0 text-signal" x-text="item.value === String(value ?? '') ? '✓' : ''"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate" x-text="item.label"></span>
                        <span x-show="item.hint" class="block truncate text-2xs text-zinc-500" x-text="item.hint"></span>
                    </span>
                    <span x-show="item.count != null" class="font-mono text-2xs text-zinc-500" x-text="item.count?.toLocaleString(document.documentElement.lang)"></span>
                </li>
            </template>
            <li x-show="!filtered.length" class="px-2.5 py-3 text-center text-sm text-zinc-500">{{ __('Nothing found') }}</li>
        </ul>
    </div>
</div>
