<div class="-mx-4 mt-4 border-t border-white/8 px-4 pt-4">
    <p class="eyebrow">{{ __('Phone') }}</p>

    @if (filled($whatsapp['connected_at'] ?? null))
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <span class="chip chip-signal">{{ __('WhatsApp connected') }}{{ filled($whatsapp['phone'] ?? null) ? ' · +'.$whatsapp['phone'] : '' }}</span>
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="disconnectPhone" :wire:confirm="__('Disconnect this phone? Sending and WhatsApp checks stop until you pair again.')">{{ __('Disconnect phone') }}</flux:button>
        </div>
    @elseif ($whatsappQr)
        <div wire:poll.3s="checkPhone" class="mt-3 flex flex-col items-center gap-3 text-center">
            <img src="{{ $whatsappQr }}" alt="{{ __('WhatsApp QR code') }}" class="size-56 rounded-lg bg-white p-2" />
            <p class="text-sm text-zinc-400">{{ __('On your phone, open WhatsApp › Linked devices › Link a device and scan the code.') }}</p>
            <p class="font-mono text-2xs text-zinc-500">{{ __('Waiting for the scan… the code renews by itself.') }}</p>
            <flux:button size="sm" variant="ghost" wire:click="cancelPairing">{{ __('Cancel') }}</flux:button>
        </div>
    @else
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-zinc-400">{{ __('Pair the phone that will check numbers and send the messages.') }}</p>
            <flux:button size="sm" variant="primary" icon="device-phone-mobile" wire:click="connectPhone">{{ __('Connect phone') }}</flux:button>
        </div>
    @endif
</div>
