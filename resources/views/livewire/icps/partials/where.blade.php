{{-- Where to search: shared by manual step 4 and Ask AI step 5. --}}
@include('livewire.icps.partials.tags', ['field' => 'regions', 'label' => __('Regions (cities, neighborhoods)'), 'placeholder' => __('e.g. São Paulo, Moema, Miami (Enter adds)')])

@include('livewire.icps.partials.area-map')

<div x-data="{ q: '' }" class="space-y-3">
    <flux:input x-model="q" :label="__('Countries')" :placeholder="__('Filter countries…')" icon="magnifying-glass" clearable />
    <div class="grid max-h-64 grid-cols-2 gap-x-4 gap-y-1 overflow-y-auto rounded-xl border border-white/10 bg-ink p-3 sm:grid-cols-3">
        <flux:checkbox.group wire:model.live="draft.countries" class="contents">
            @foreach ($countries as $code => $name)
                <div wire:key="country-{{ $code }}" x-show="!q || @js(Str::lower(Str::ascii($name)).' '.strtolower($code)).includes(q.toLowerCase())">
                    <flux:checkbox value="{{ $code }}" label="{{ $name }}" />
                </div>
            @endforeach
        </flux:checkbox.group>
    </div>
    <flux:error name="draft.countries" />
    @if ($draft['countries'])
        <div class="flex flex-wrap gap-1">
            @foreach ($draft['countries'] as $code)
                <span wire:key="chip-{{ $code }}" class="chip">{{ \App\Support\Countries::name($code) }}</span>
            @endforeach
        </div>
    @endif
</div>
