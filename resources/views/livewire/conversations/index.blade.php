<div>
    <x-ui.page-header
        eyebrow="03 / {{ __('Conversations') }}"
        :title="__('Conversations')"
        :accent="__('only with your leads.')"
        :description="__('WhatsApp chats with numbers that are leads in your projects. Other chats on your phone never reach Omni Leads.')">
        <div x-data="{ state: 'Notification' in window ? Notification.permission : 'unsupported' }" x-show="state === 'default'" x-cloak>
            <flux:button icon="bell" x-on:click="Notification.requestPermission().then(p => state = p)">{{ __('Enable notifications') }}</flux:button>
        </div>
    </x-ui.page-header>

    @unless ($connected)
        <x-ui.empty :title="__('Connect your WhatsApp')" data-reveal>
            {{ __('Pair your phone in Settings > Integrations. Replies from your leads will show up here.') }}
            <x-slot name="actions">
                <flux:button :href="route('integrations.edit')" wire:navigate variant="primary" icon="key">{{ __('Integrations') }}</flux:button>
            </x-slot>
        </x-ui.empty>
    @else
        <div class="panel grid h-[calc(100svh-15rem)] min-h-[28rem] grid-cols-1 overflow-hidden lg:grid-cols-12" data-reveal>
            <aside @class(['flex min-h-0 flex-col border-white/6 lg:col-span-4 lg:border-r', 'hidden lg:flex' => $open])>
                <div class="border-b border-white/6 p-3">
                    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name, company or phone')" size="sm" clearable />
                </div>
                <ul class="min-h-0 flex-1 divide-y divide-white/6 overflow-y-auto">
                    @forelse ($conversations as $item)
                        <li wire:key="conversation-{{ $item->id }}">
                            <button type="button" wire:click="open({{ $item->id }})" @class(['flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-white/3', 'bg-white/6' => $open?->id === $item->id])>
                                <x-whatsapp.avatar :conversation="$item" class="size-9" />
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-baseline justify-between gap-2">
                                        <span @class(['truncate text-sm', 'font-semibold text-white' => $item->unread_count > 0, 'text-zinc-200' => $item->unread_count === 0])>{{ $item->displayName() }}</span>
                                        <span class="font-mono shrink-0 text-2xs {{ $item->unread_count > 0 ? 'text-signal' : 'text-zinc-600' }}">@php($at = $item->last_message_at?->local()){{ $at?->isSameDay(now()->local()) ? $at->format('H:i') : $at?->format('d/m') }}</span>
                                    </span>
                                    <span class="mt-0.5 block truncate text-2xs text-zinc-500">{{ $item->leads->map(fn ($l) => $l->displayName())->unique()->join(', ') }}</span>
                                    <span class="mt-1 flex items-center justify-between gap-2">
                                        <span class="truncate text-xs text-zinc-400">{{ $item->last_message_preview }}</span>
                                        @if ($item->unread_count > 0)
                                            <span class="font-mono shrink-0 rounded-full bg-signal px-1.5 text-2xs font-semibold text-zinc-950">{{ $item->unread_count }}</span>
                                        @endif
                                    </span>
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="p-6 text-sm text-zinc-500">{{ $search !== '' ? __('No conversation matches the search.') : __('No conversations yet. When a lead writes to you, or you message one, it shows up here.') }}</li>
                    @endforelse
                </ul>
            </aside>

            <section @class(['min-h-0 lg:col-span-8', 'hidden lg:block' => ! $open])>
                @if ($open)
                    <div class="flex h-full min-h-0 flex-col">
                        <div class="border-b border-white/6 px-3 py-2 lg:hidden">
                            <flux:button size="xs" variant="ghost" icon="arrow-left" wire:click="close">{{ __('Conversations') }}</flux:button>
                        </div>
                        <div class="min-h-0 flex-1">
                            <livewire:conversations.chat :conversation-id="$open->id" :key="'chat-'.$open->id" />
                        </div>
                    </div>
                @else
                    <div class="flex h-full items-center justify-center p-8 text-center">
                        <div>
                            <p class="font-mono text-2xs uppercase tracking-label text-zinc-600">whatsapp</p>
                            <p class="mt-2 text-sm text-zinc-500">{{ __('Pick a conversation on the left.') }}</p>
                        </div>
                    </div>
                @endif
            </section>
        </div>
    @endunless
</div>
