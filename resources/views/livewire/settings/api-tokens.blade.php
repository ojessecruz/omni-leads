<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('API') }}</flux:heading>

    <x-settings.layout :heading="__('API')" :subheading="__('Tokens let automations (n8n and alike) read your WhatsApp conversations and reply. Send it as Authorization: Bearer <token>.')">
        <form wire:submit="create" class="flex flex-col gap-4">
            <flux:input wire:model="name" :label="__('Token name')" :placeholder="__('e.g. n8n')" required />

            <flux:checkbox.group wire:model="abilities" :label="__('Permissions')">
                @foreach ($labels as $ability => $label)
                    <flux:checkbox wire:key="ability-{{ $ability }}" :value="$ability" :label="$label" />
                @endforeach
            </flux:checkbox.group>

            <div>
                <flux:button type="submit" variant="primary" icon="key" data-test="create-token-button">{{ __('Create token') }}</flux:button>
            </div>
        </form>

        @if ($plainToken)
            <div class="panel mt-6 p-4" data-test="plain-token">
                <p class="text-sm text-white">{{ __('Copy the token now. It will not be shown again.') }}</p>
                <flux:input class="mt-3" :value="$plainToken" readonly copyable />
            </div>
        @endif

        <div class="mt-10">
            <p class="eyebrow">{{ __('Endpoints') }}</p>
            <div class="font-mono mt-3 space-y-1 text-2xs text-zinc-400">
                <p><span class="text-signal">GET</span> {{ route('api.conversations.awaiting') }}</p>
                <p><span class="text-signal">GET</span> {{ url('api/conversations/{id}/messages') }}</p>
                <p><span class="text-signal">POST</span> {{ url('api/conversations/{id}/messages') }} <span class="text-zinc-600">{"text": "…"}</span></p>
            </div>
        </div>

        <div class="mt-10">
            <p class="eyebrow">{{ __('Tokens') }}</p>

            @forelse ($tokens as $token)
                <div wire:key="token-{{ $token->id }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-white/6 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm text-white">{{ $token->name }}</p>
                        <p class="font-mono mt-0.5 text-2xs text-zinc-500">
                            {{ collect($token->abilities)->map(fn ($a) => $labels[$a] ?? $a)->join(' · ') }}
                            · {{ $token->last_used_at ? __('Last used :when', ['when' => $token->last_used_at->diffForHumans()]) : __('Never used') }}
                        </p>
                    </div>

                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="revoke({{ $token->id }})" wire:confirm="{{ __('Revoke the token :name?', ['name' => $token->name]) }}">{{ __('Revoke') }}</flux:button>
                </div>
            @empty
                <p class="mt-4 text-sm text-zinc-500">{{ __('No tokens yet.') }}</p>
            @endforelse
        </div>
    </x-settings.layout>
</section>
