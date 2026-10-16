<div class="mt-6 border-t border-white/8 pt-4">
    <p class="eyebrow">{{ __('Phone') }}</p>

    @if (filled($whatsapp['connected_at'] ?? null))
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <span class="chip chip-signal">{{ __('WhatsApp connected') }}{{ filled($whatsapp['phone'] ?? null) ? ' · +'.$whatsapp['phone'] : '' }}</span>
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="disconnectPhone" :wire:confirm="__('Disconnect this phone? Sending and WhatsApp checks stop until you pair again.')">{{ __('Disconnect phone') }}</flux:button>
        </div>
        {{-- Replies and delivery ticks reach the inbox through this webhook. --}}
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
            @if (filled($whatsapp['webhook_url'] ?? null))
                <span class="text-zinc-400"><span class="text-signal">●</span> {{ __('Inbox receiving replies and delivery status.') }}</span>
            @elseif (filled($whatsapp['webhook_error'] ?? null))
                <span class="min-w-0 text-red-400">{{ __('Inbox not receiving: :reason', ['reason' => $whatsapp['webhook_error']]) }}</span>
            @else
                <span class="text-zinc-400"><span class="text-amber-400">●</span> {{ __('Inbox webhook not registered yet.') }}</span>
            @endif
            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="registerInboxWebhook">{{ __('Register webhook') }}</flux:button>
        </div>
        {{-- First contacts are paced to keep the number from being restricted (config/whatsapp.php). --}}
        <p class="mt-3 text-xs text-zinc-500">
            {{ __('First contacts: up to :hour per hour and :day per day (the daily limit grows while a new number warms up), :gap_min–:gap_max min apart.', ['hour' => $pacing['hour'], 'day' => $pacing['day'], 'gap_min' => $pacing['gap'][0], 'gap_max' => $pacing['gap'][1]]) }}
            @if ($pacing['hours']['enabled'])
                {{ __('Only on weekdays, :from:00–:until:00.', ['from' => $pacing['hours']['from'], 'until' => $pacing['hours']['until']]) }}
            @endif
        </p>
    @elseif ($whatsappQr)
        <div wire:poll.3s="checkPhone" class="mt-3 flex flex-col items-center gap-3 text-center">
            <img src="{{ $whatsappQr }}" alt="{{ __('WhatsApp QR code') }}" class="size-56 rounded-lg bg-[#fff] p-2" />
            <p class="text-sm text-zinc-400">{{ __('On your phone, open WhatsApp › Linked devices › Link a device and scan the code.') }}</p>
            <p class="font-mono text-2xs text-zinc-500">{{ __('Waiting for the scan… the code renews by itself.') }}</p>
            <flux:button size="sm" variant="ghost" wire:click="cancelPairing">{{ __('Cancel') }}</flux:button>
        </div>
    @else
        @if (filled($whatsapp['disconnected_at'] ?? null))
            <p class="mt-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-zinc-300">
                {{ __('Disconnected on :time: :reason', ['time' => \App\Actions\WhatsApp\QueueFirstContact::localTime($whatsapp['disconnected_at']), 'reason' => $whatsapp['disconnect_reason'] ?? '—']) }}
            </p>
        @endif
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-zinc-400">{{ __('Pair the phone that will check numbers and send the messages.') }}</p>
            <flux:button size="sm" variant="primary" icon="device-phone-mobile" wire:click="connectPhone">{{ __('Connect phone') }}</flux:button>
        </div>
    @endif
</div>
