{{-- Shown on every page after a send failed because the phone dropped: sending stays paused until it is paired again. --}}
@php($disconnectedAt = auth()->user()?->integration(\App\Enums\IntegrationProvider::Evolution)?->credentials['disconnected_at'] ?? null)

@if ($disconnectedAt)
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-zinc-200" role="alert">
        <p>
            <flux:icon.exclamation-triangle variant="micro" class="mr-1 inline text-amber-400" />
            {{ __('Your WhatsApp disconnected on :time and sending is paused. Scheduled messages were cancelled. If WhatsApp restricted the number, wait for it to be lifted before pairing again.', ['time' => \App\Actions\WhatsApp\QueueFirstContact::localTime($disconnectedAt)]) }}
        </p>
        <flux:button size="sm" :href="route('integrations.edit', ['open' => 'evolution'])" wire:navigate icon="qr-code">{{ __('Reconnect') }}</flux:button>
    </div>
@endif
