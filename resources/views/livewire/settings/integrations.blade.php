<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Integrations') }}</flux:heading>

    <x-settings.layout :heading="__('Integrations')" :subheading="__('Your own API keys (BYOK). Each one is checked before saving, stored encrypted and used by all your projects.')">
        <div class="space-y-10">
            @foreach ($groups as $group => $providers)
                <div wire:key="group-{{ $loop->index }}">
                    <p class="eyebrow">{{ $group }}</p>

                    <div class="mt-4 space-y-4">
                        @foreach ($providers as $provider)
                            @php($integration = $connected->get($provider->value))
                            @php($hosted = $provider === \App\Enums\IntegrationProvider::Evolution && $form['evolution']['mode'] === 'hosted')
                            <form wire:key="integration-{{ $provider->value }}" wire:submit="save('{{ $provider->value }}')" class="rounded-xl border border-white/8 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p class="font-semibold text-white">{{ $provider->label() }}</p>
                                        <p class="mt-1 text-sm text-zinc-400">{{ $provider->description() }}</p>
                                    </div>
                                    <div class="flex gap-1.5">
                                        @if ($provider->required())
                                            <span class="chip">{{ __('Required') }}</span>
                                        @endif
                                        @if ($integration)
                                            <span class="chip chip-signal">{{ __('Connected') }} · {{ ($integration->credentials['mode'] ?? 'own') === 'hosted' ? 'Omni Leads' : $integration->maskedKey() }}</span>
                                        @endif
                                    </div>
                                </div>

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
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-settings.layout>
</section>
