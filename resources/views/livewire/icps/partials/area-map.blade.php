@php
    use App\Domains\Icp\Data\GeoArea;

    // Initial view when nothing is drawn yet: Brazil when it is among the countries, otherwise the world.
    $mapTiles = config('services.map_tiles');
    $mapCenter = in_array('BR', $draft['countries'], true) ? ['lat' => -14.2, 'lng' => -51.9, 'zoom' => 4] : ['lat' => 20, 'lng' => 0, 'zoom' => 2];
@endphp

<div
    x-data="areaMap({ center: @js($mapCenter), minRadius: {{ GeoArea::MIN_RADIUS_KM }}, maxRadius: {{ GeoArea::MAX_RADIUS_KM }}, tiles: @js(['url' => $mapTiles['url'], 'attribution' => $mapTiles['attribution']]) })"
    x-effect="drawAreas($wire.draft.areas)"
    class="space-y-3"
>
    <div>
        <span class="eyebrow text-zinc-400">{{ __('Radius on the map') }}</span>
        <p class="mt-1 text-sm text-zinc-500">{{ __('Search a place or click on the map, choose the radius and add. Google Places only returns businesses inside the circle.') }}</p>
    </div>

    <div class="flex gap-2">
        <div class="min-w-0 flex-1">
            <flux:input wire:model="placeQuery" wire:keydown.enter.prevent="searchPlace" icon="magnifying-glass" :placeholder="__('Address, neighborhood or city')" :aria-label="__('Search a place')" />
        </div>
        <flux:button type="button" wire:click="searchPlace" wire:loading.attr="disabled" wire:target="searchPlace">{{ __('Search') }}</flux:button>
    </div>
    <flux:error name="placeQuery" />

    @if ($placeResults)
        <ul class="divide-y divide-white/6 overflow-hidden rounded-xl border border-white/10 bg-ink">
            @foreach ($placeResults as $i => $place)
                <li wire:key="place-{{ $i }}">
                    <button type="button"
                            x-on:click="pick({{ $place['lat'] }}, {{ $place['lng'] }}, @js($place['label']))"
                            class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm text-zinc-300 transition hover:bg-white/4 hover:text-white">
                        <span class="text-signal">→</span>
                        <span class="truncate">{{ $place['label'] }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif

    <div wire:ignore class="relative isolate">
        <div x-ref="map" @class(['area-map h-80 w-full overflow-hidden rounded-xl border border-white/10 bg-ink', 'area-map-dark' => $mapTiles['dark_filter']])></div>
        <p x-show="!ready" class="font-mono absolute inset-0 flex items-center justify-center text-2xs uppercase tracking-label text-zinc-600">{{ __('Loading map…') }}</p>
    </div>

    <div x-show="pending" x-cloak class="space-y-3 rounded-xl border border-signal/30 bg-signal/6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-mono text-2xs uppercase tracking-label text-zinc-400" x-text="pending ? pending.lat.toFixed(4) + ', ' + pending.lng.toFixed(4) : ''"></span>
            <span class="font-mono text-sm text-signal"><span x-text="radius"></span> km</span>
        </div>
        <input type="range" x-model.number="radius" :min="minRadius" :max="maxRadius" step="0.5" class="w-full accent-signal" aria-label="{{ __('Radius (km)') }}">
        <flux:input x-model="label" :label="__('Area name')" :placeholder="__('e.g. Moema, São Paulo')" />
        <div class="flex justify-end gap-2">
            <flux:button type="button" size="sm" variant="ghost" x-on:click="clearPending()">{{ __('Cancel') }}</flux:button>
            <flux:button type="button" size="sm" variant="primary" icon="plus" x-on:click="add()">{{ __('Add area') }}</flux:button>
        </div>
    </div>

    <flux:error name="draft.areas" />
    <flux:error name="radius_km" />

    @if ($draft['areas'])
        <div class="flex flex-wrap gap-1.5">
            @foreach ($draft['areas'] as $i => $area)
                @php($geo = GeoArea::fromArray($area))
                @if ($geo)
                    <span class="chip" wire:key="area-{{ $i }}">
                        <button type="button" x-on:click="focus({{ $i }})" class="hover:text-white">{{ $geo->displayName() }}</button>
                        <button type="button" wire:click="removeArea({{ $i }})" class="-mr-1 text-zinc-500 hover:text-white" aria-label="{{ __('Remove') }}">×</button>
                    </span>
                @endif
            @endforeach
        </div>
    @endif
</div>
