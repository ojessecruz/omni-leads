<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-200') }}>
    <p>{{ __('Searches need your Google Places API key. Without it no lead is found.') }}</p>
    <flux:button size="sm" :href="route('integrations.edit')" wire:navigate icon="key">{{ __('Add key') }}</flux:button>
</div>
