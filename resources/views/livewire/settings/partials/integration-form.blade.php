{{-- Settings for one provider, inside the side panel. --}}
@php($integration = $connected->get($provider->value))
@php($hosted = $provider === \App\Enums\IntegrationProvider::Evolution && $form['evolution']['mode'] === 'hosted')
<form wire:key="integration-{{ $provider->value }}" wire:submit="save('{{ $provider->value }}')">
    @if ($provider === \App\Enums\IntegrationProvider::GooglePlaces)
        @include('livewire.settings.partials.google-places-guide', ['connected' => (bool) $integration])
    @endif

    @if ($provider === \App\Enums\IntegrationProvider::Evolution && $hostedWhatsApp)
        <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2" role="radiogroup" aria-label="{{ __('WhatsApp server') }}">
            @foreach (['hosted' => [__('Omni Leads server'), __('Just scan the QR code. Nothing to install.')], 'own' => [__('My own server'), __('Your Evolution API URL and global key.')]] as $mode => [$title, $hint])
                <label wire:key="evolution-mode-{{ $mode }}" class="cursor-pointer rounded-lg border p-3 {{ $form['evolution']['mode'] === $mode ? 'border-signal/60 bg-signal/6' : 'border-white/8 hover:border-white/20' }}">
                    <input type="radio" wire:model.live="form.evolution.mode" value="{{ $mode }}" class="sr-only" />
                    <span class="block text-sm font-medium text-white">{{ $title }}</span>
                    <span class="mt-0.5 block text-xs text-zinc-400">{{ $hint }}</span>
                </label>
            @endforeach
        </div>
        <flux:error name="form.evolution.mode" />
    @endif

    <div @class(['mt-4 space-y-3', 'hidden' => $hosted])>
        @foreach ($provider->fields() as $name => $field)
            <flux:input
                wire:key="field-{{ $provider->value }}-{{ $name }}"
                wire:model="form.{{ $provider->value }}.{{ $name }}"
                :type="$field['secret'] ? 'password' : 'text'"
                :label="$field['label']"
                :placeholder="$field['secret'] && $integration ? __('Leave blank to keep the saved key') : ''"
                class="font-mono"
                autocomplete="off"
            />
        @endforeach
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        @if ($hosted)
            <span></span>
        @else
            <a href="{{ $provider->docsUrl() }}" target="_blank" rel="noopener" class="font-mono text-2xs uppercase tracking-label text-zinc-500 hover:text-white">{{ __('Where to get the key') }} ↗</a>
        @endif
        <div class="flex gap-2">
            @if ($integration)
                <flux:button size="sm" variant="ghost" icon="trash" wire:click="remove('{{ $provider->value }}')" :wire:confirm="__('Remove this key? Features that use it stop working.')">{{ __('Remove') }}</flux:button>
            @endif
            @unless ($hosted && ($integration?->credentials['mode'] ?? null) === 'hosted')
                <flux:button size="sm" type="submit" variant="primary">{{ $hosted ? __('Use this server') : ($integration ? __('Update') : __('Connect')) }}</flux:button>
            @endunless
        </div>
    </div>

    @if ($provider === \App\Enums\IntegrationProvider::Evolution && $integration)
        @if (($integration->credentials['mode'] ?? 'own') === $form['evolution']['mode'])
            @include('livewire.settings.partials.whatsapp-pairing')
        @endif
    @endif
</form>
