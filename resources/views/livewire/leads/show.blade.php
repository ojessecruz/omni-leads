<div>
    <x-ui.page-header :title="$lead->displayName()">
        <x-slot name="crumbs">
            <x-ui.crumb :href="route('leads.index')">{{ __('Leads') }}</x-ui.crumb>
            <x-ui.crumb>{{ $lead->displayName() }}</x-ui.crumb>
        </x-slot>
        <flux:badge :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge>
        @if ($canWhatsApp)
            <flux:modal.trigger name="whatsapp">
                <flux:button icon="chat-bubble-left-right" variant="primary">{{ __('Send WhatsApp') }}</flux:button>
            </flux:modal.trigger>
        @endif
        @if ($outbound)
            @if ($outboundSent)
                <span class="chip chip-signal">{{ __('Sent to :connector', ['connector' => $outboundLabel]) }}</span>
            @else
                <flux:button icon="envelope" wire:click="pushToOutbound">{{ __('Send to :connector', ['connector' => $outboundLabel]) }}</flux:button>
            @endif
        @endif
        <flux:button icon="sparkles" wire:click="enrich">{{ __('Enrich') }}</flux:button>
        <flux:dropdown>
            <flux:button icon-trailing="chevron-down">{{ __('Change status') }}</flux:button>
            <flux:menu>
                @foreach ($statuses as $s)
                    <flux:menu.item wire:key="status-{{ $s->value }}" wire:click="setStatus('{{ $s->value }}')" :disabled="$lead->status === $s">{{ $s->label() }}</flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12" data-reveal>
        <div class="space-y-6 lg:col-span-7">
            <x-ui.panel :eyebrow="__('Lead')" :title="__('Identity & contact')">
                <x-slot name="actions"><span class="chip">#{{ $lead->id }}</span></x-slot>
                <dl class="kv grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.kv :label="__('Name')">{{ $lead->name ?? '—' }}</x-ui.kv>
                    <x-ui.kv :label="__('Website')">
                        @if ($lead->domain)<a href="{{ $lead->websiteUrl() }}" target="_blank" rel="noopener" class="font-mono text-signal hover:underline">{{ $lead->domain }}</a>@else — @endif
                    </x-ui.kv>
                    <x-ui.kv :label="__('Email')"><span class="font-mono">{{ $lead->email ?? '—' }}</span></x-ui.kv>
                    <x-ui.kv :label="__('Phone')">
                        <span class="font-mono">{{ $lead->phone ? '+'.$lead->phone : '—' }}</span>
                        @if ($lead->whatsapp_valid === true)<span class="chip chip-signal ml-2">WhatsApp</span>@elseif ($lead->whatsapp_valid === false)<span class="chip ml-2 border-red-500/40 text-red-400">{{ __('no WhatsApp') }}</span>@endif
                    </x-ui.kv>
                    <x-ui.kv :label="__('Location')">{{ $lead->city ? $lead->city.', ' : '' }}{{ \App\Support\Countries::name($lead->country) }}</x-ui.kv>
                    <x-ui.kv :label="__('Tax ID')"><span class="font-mono">{{ $lead->cnpj ?? '—' }}</span></x-ui.kv>
                    <x-ui.kv :label="__('ICP')"><a href="{{ route('icps.show', $lead->icp) }}" wire:navigate class="text-white hover:text-signal">{{ $lead->icp->name }}</a></x-ui.kv>
                    <x-ui.kv :label="__('Discovered')"><span class="font-mono">{{ $lead->created_at->diffForHumans() }}</span></x-ui.kv>
                    <x-ui.kv :label="__('Enriched')"><span class="font-mono">{{ $lead->enriched_at?->diffForHumans() ?? __('not yet') }}</span></x-ui.kv>
                </dl>
            </x-ui.panel>

            <x-ui.panel :eyebrow="__('Sources')" :title="trans_choice(':count record|:count records', $sources->count())" :padding="false">
                @forelse ($sources as $source)
                    <details wire:key="source-{{ $source->id }}" class="group border-b border-white/6 last:border-b-0">
                        <summary class="font-mono flex cursor-pointer items-center justify-between gap-3 px-5 py-3 text-xs hover:bg-white/3">
                            <span class="flex items-center gap-3">
                                <span class="text-zinc-600 transition group-open:rotate-90">→</span>
                                <span class="chip">{{ $source->driver }}</span>
                                <span class="truncate text-zinc-500">{{ $source->external_id }}</span>
                            </span>
                            <span class="shrink-0 text-zinc-500">{{ $source->updated_at->diffForHumans() }}</span>
                        </summary>
                        <pre class="font-mono overflow-x-auto border-t border-white/6 bg-ink p-4 text-2xs leading-5 text-zinc-300">{{ json_encode($source->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @empty
                    <p class="p-5 text-sm text-zinc-500">{{ __('No requests recorded.') }}</p>
                @endforelse
            </x-ui.panel>
        </div>

        <div class="lg:col-span-5">
            <div class="panel sticky top-24 overflow-hidden shadow-glow">
                <div class="panel-head">
                    <span class="font-mono text-2xs text-zinc-500">score.json — {{ $lead->icp->name }}</span>
                    <span class="chip {{ $scoring['score'] >= config('lead-scoring.qualified_threshold') ? 'chip-signal' : '' }}">score {{ $scoring['score'] >= config('lead-scoring.qualified_threshold') ? '≥' : '<' }} {{ config('lead-scoring.qualified_threshold') }}</span>
                </div>
                <div class="p-5">
                    <div class="flex items-end justify-between">
                        <div>
                            <p class="eyebrow">{{ __('Qualification score') }}</p>
                            <p class="font-mono mt-2 text-6xl font-medium tracking-tight {{ $scoring['score'] >= config('lead-scoring.qualified_threshold') ? 'text-signal' : 'text-white' }}">{{ $scoring['score'] }}<span class="text-xl text-zinc-600">/100</span></p>
                        </div>
                        <p class="font-mono text-2xs text-zinc-500">{{ __('threshold') }} {{ config('lead-scoring.qualified_threshold') }}</p>
                    </div>
                    <div class="mt-4 h-1 w-full rounded-full bg-white/6">
                        <div class="bar h-1 rounded-full bg-signal" style="width: {{ $scoring['score'] }}%"></div>
                    </div>
                    <ul class="font-mono mt-5 divide-y divide-white/6 text-xs">
                        @foreach ($scoring['breakdown'] as $check)
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <span class="flex min-w-0 items-center gap-2">
                                    <span class="{{ $check['met'] ? 'text-signal' : 'text-zinc-600' }}">{{ $check['met'] ? '✓' : '·' }}</span>
                                    <span class="truncate {{ $check['met'] ? 'text-zinc-200' : 'text-zinc-500' }}">{{ $check['label'] }}</span>
                                    <span class="hidden truncate text-zinc-600 sm:inline">{{ $check['detail'] }}</span>
                                </span>
                                <span class="shrink-0 {{ $check['met'] ? 'text-white' : 'text-zinc-600 line-through' }}">+{{ $check['weight'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($lead->scored_at === null || $lead->score !== $scoring['score'])
                        <p class="mt-4 text-xs text-zinc-500">{{ __('Saved score: :score. The value above is the current calculation; it is stored when the scoring job runs.', ['score' => $lead->score]) }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($canWhatsApp)
    <flux:modal name="whatsapp" class="w-full max-w-lg">
        <form wire:submit="sendWhatsApp" class="space-y-5">
            <div>
                <p class="eyebrow mb-3">evolution · sendText</p>
                <h2 class="display text-3xl font-semibold text-white">{{ __('Send WhatsApp') }}</h2>
                <p class="mt-2 text-sm text-zinc-400">{{ __('To') }} <span class="font-mono text-zinc-200">+{{ $lead->phone }}</span>{{ $lead->whatsapp_valid === true ? ' ('.__('WhatsApp validated').')' : ($lead->whatsapp_valid === false ? ' — '.__('warning: number without WhatsApp') : '') }}. {{ __('The template lives in the project settings.') }}</p>
            </div>
            <flux:textarea wire:model="whatsappText" rows="6" :label="__('Message')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    @endif
</div>
