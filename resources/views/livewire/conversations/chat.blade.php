<div class="flex h-full min-h-0 flex-col"
     x-data="{ id: {{ $conversation->id }}, init() { window.omniActiveConversation = this.id; this.$nextTick(() => this.scroll()); }, destroy() { if (window.omniActiveConversation === this.id) window.omniActiveConversation = null; }, scroll() { this.$refs.log.scrollTop = this.$refs.log.scrollHeight; } }"
     x-on:chat-scroll.window="$nextTick(() => scroll())">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-white/6 px-5 py-3">
        <x-whatsapp.avatar :conversation="$conversation" class="size-9" />
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-white">{{ $conversation->displayName() }}</p>
            <p class="font-mono text-2xs text-zinc-500">+{{ $conversation->phone }}</p>
        </div>
        <div class="flex flex-wrap gap-1.5">
            @foreach ($conversation->leads as $lead)
                <a wire:key="chat-lead-{{ $lead->id }}" href="{{ route('leads.show', $lead) }}" wire:navigate class="chip hover:border-signal/50 hover:text-white" title="{{ $lead->project->name }}">
                    {{ Str::limit($lead->displayName(), 24) }} · {{ Str::limit($lead->project->name, 18) }}
                </a>
            @endforeach
        </div>
    </div>

    <div x-ref="log" class="min-h-0 flex-1 space-y-2 overflow-y-auto px-5 py-4">
        @if ($hasOlder)
            <div class="text-center">
                <flux:button size="xs" variant="ghost" wire:click="loadOlder">{{ __('Load older messages') }}</flux:button>
            </div>
        @endif

        @php($day = null)
        @forelse ($messages as $message)
            @if ($day !== $message->sent_at->toDateString())
                @php($day = $message->sent_at->toDateString())
                <p wire:key="day-{{ $day }}" class="font-mono py-2 text-center text-2xs uppercase tracking-label text-zinc-600">{{ $message->sent_at->isToday() ? __('Today') : $message->sent_at->translatedFormat('d M Y') }}</p>
            @endif
            <div wire:key="msg-{{ $message->id }}" @class(['flex', 'justify-end' => $message->from_me])>
                <div @class([
                    'max-w-[80%] rounded-2xl px-3.5 py-2 text-sm',
                    'rounded-br-sm bg-signal/12 text-zinc-100' => $message->from_me,
                    'rounded-bl-sm bg-white/6 text-zinc-100' => ! $message->from_me,
                ])>
                    @if ($message->type->hasMedia())
                        @if ($message->media_path)
                            @php($src = route('conversations.media', $message))
                            @switch($message->type)
                                @case(\App\Enums\WhatsAppMessageType::Image)
                                @case(\App\Enums\WhatsAppMessageType::Sticker)
                                    <a href="{{ $src }}" target="_blank" rel="noopener"><img src="{{ $src }}" alt="" loading="lazy" @class(['rounded-lg', 'max-h-72' => $message->type === \App\Enums\WhatsAppMessageType::Image, 'size-32' => $message->type === \App\Enums\WhatsAppMessageType::Sticker])></a>
                                    @break
                                @case(\App\Enums\WhatsAppMessageType::Audio)
                                    <audio controls preload="none" src="{{ $src }}" class="max-w-64"></audio>
                                    @break
                                @case(\App\Enums\WhatsAppMessageType::Video)
                                    <video controls preload="none" src="{{ $src }}" class="max-h-72 rounded-lg"></video>
                                    @break
                                @default
                                    <a href="{{ $src }}" target="_blank" rel="noopener" class="flex items-center gap-2 hover:text-signal">
                                        <flux:icon.document-text variant="micro" />
                                        <span class="truncate">{{ $message->media_name ?? __('Document') }}</span>
                                    </a>
                            @endswitch
                        @else
                            <p class="font-mono text-2xs text-zinc-400">[{{ $message->type->label() }}]</p>
                        @endif
                    @elseif ($message->type === \App\Enums\WhatsAppMessageType::Other)
                        <p class="font-mono text-2xs text-zinc-400">[{{ $message->type->label() }}]</p>
                    @endif

                    @if (filled($message->body))
                        <p @class(['whitespace-pre-line break-words', 'mt-1.5' => $message->type->hasMedia()])>{{ $message->body }}</p>
                    @endif

                    <p class="font-mono mt-1 flex items-center justify-end gap-1.5 text-2xs text-zinc-500">
                        @if ($message->from_me && $message->sent_via === 'phone')<span>{{ __('phone') }}</span>@endif
                        <span>{{ $message->sent_at->format('H:i') }}</span>
                        @if ($message->from_me)
                            <span @class(['text-signal' => $message->status === \App\Enums\WhatsAppMessageStatus::Read, 'text-red-400' => $message->status === \App\Enums\WhatsAppMessageStatus::Failed]) title="{{ $message->status?->label() }}">
                                {{ match ($message->status) { \App\Enums\WhatsAppMessageStatus::Pending, null => '·', \App\Enums\WhatsAppMessageStatus::Sent => '✓', \App\Enums\WhatsAppMessageStatus::Failed => '!', default => '✓✓' } }}
                            </span>
                        @endif
                    </p>
                </div>
            </div>
        @empty
            <p class="py-10 text-center text-sm text-zinc-500">{{ __('No messages yet.') }}</p>
        @endforelse

        @foreach ($pending as $i => $text)
            <div wire:key="pending-{{ $i }}" class="flex justify-end">
                <div class="max-w-[80%] rounded-2xl rounded-br-sm bg-signal/6 px-3.5 py-2 text-sm text-zinc-400">
                    <p class="whitespace-pre-line break-words">{{ $text }}</p>
                    <p class="font-mono mt-1 text-right text-2xs text-zinc-600">{{ __('Sending') }}…</p>
                </div>
            </div>
        @endforeach
    </div>

    <form wire:submit="send" class="border-t border-white/6 p-3">
        @unless ($hasReplied)
            <p class="mb-2 text-xs text-amber-300/80">{{ __('This number has not replied yet. Messages to numbers that never replied are limited to :count per hour to protect your WhatsApp.', ['count' => \App\Domains\WhatsApp\FirstContactLimit::PER_HOUR]) }}</p>
        @endunless
        <div class="flex items-end gap-2">
            <flux:textarea wire:model="text" rows="1" resize="vertical" :placeholder="__('Type a message')" class="flex-1"
                           x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send(); }" />
            <flux:button type="submit" variant="primary" icon="paper-airplane" :aria-label="__('Send')" />
        </div>
        <flux:error name="text" />
    </form>
</div>
