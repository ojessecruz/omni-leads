<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Integrations') }}</flux:heading>

    <x-settings.layout :heading="__('Integrations')" wide :subheading="__('Your own API keys (BYOK). Each one is checked before saving, stored encrypted and used by all your projects.')">
        <div class="space-y-8">
            @foreach ($groups as $group => $providers)
                <div wire:key="group-{{ $loop->index }}">
                    <p class="eyebrow">{{ $group }}</p>

                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($providers as $provider)
                            @php($status = $statuses[$provider->value])
                            <button
                                type="button"
                                wire:key="tile-{{ $provider->value }}"
                                wire:click="edit('{{ $provider->value }}')"
                                data-integration-tile="{{ $provider->value }}"
                                @class([
                                    'group relative flex items-center gap-3 rounded-xl border p-4 text-left transition',
                                    'border-signal/30 bg-signal/4 hover:border-signal/60' => $status['tone'] === 'ok',
                                    'border-amber-500/40 hover:border-amber-400/70' => $status['tone'] === 'warn',
                                    'border-white/8 hover:border-white/20' => $status['tone'] === 'idle',
                                ])
                            >
                                <span class="flex size-11 shrink-0 items-center justify-center rounded-lg {{ $provider->accent() }}">
                                    <flux:icon :name="$provider->icon()" class="size-5" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold text-white">{{ $provider->label() }}</span>
                                    <span @class([
                                        'mt-0.5 flex items-center gap-1.5 font-mono text-2xs uppercase tracking-label',
                                        'text-signal' => $status['tone'] === 'ok',
                                        'text-amber-400' => $status['tone'] === 'warn',
                                        'text-zinc-500' => $status['tone'] === 'idle',
                                    ])>
                                        <span class="size-1.5 rounded-full bg-current"></span>{{ $status['label'] }}
                                    </span>
                                </span>
                                @if ($status['tone'] === 'ok')
                                    <flux:icon.check-circle variant="solid" class="size-5 shrink-0 text-signal" />
                                @else
                                    <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-500 transition group-hover:translate-x-0.5 group-hover:text-white" />
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-settings.layout>

    <flux:modal wire:model="showEditor" variant="flyout" class="w-full md:w-[32rem]">
        @if ($editing)
            @php($provider = $editing)
            @php($integration = $connected->get($provider->value))
            <div wire:key="editor-{{ $provider->value }}">
                <div class="flex items-start gap-3 pr-8">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-lg {{ $provider->accent() }}">
                        <flux:icon :name="$provider->icon()" class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <flux:heading size="lg">{{ $provider->label() }}</flux:heading>
                        <p class="mt-1 text-sm text-zinc-400">{{ $provider->description() }}</p>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @if ($provider->required())
                        <span class="chip">{{ __('Required') }}</span>
                    @endif
                    @if ($integration)
                        <span class="chip chip-signal">{{ __('Connected') }} · {{ ($integration->credentials['mode'] ?? 'own') === 'hosted' ? 'Omni Leads' : $integration->maskedKey() }}</span>
                    @endif
                </div>

                @include('livewire.settings.partials.integration-form')
            </div>
        @endif
    </flux:modal>
</section>
