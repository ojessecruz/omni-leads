<div>
    <x-ui.page-header :title="__('Settings')" :accent="__('outbound & maintenance.')">
        <x-slot name="crumbs">
            <x-ui.crumb :href="route('projects.index')">{{ __('Projects') }}</x-ui.crumb>
            <x-ui.crumb :href="route('projects.show', $project)">{{ $project->name }}</x-ui.crumb>
            <x-ui.crumb>{{ __('Settings') }}</x-ui.crumb>
        </x-slot>
    </x-ui.page-header>

    <div class="space-y-6" data-reveal>
        <x-ui.panel :eyebrow="__('Outbound')" :title="__('Cold email')">
            @if ($outbound)
                <x-slot name="actions"><span class="chip chip-signal">{{ $outboundLabel }} · {{ $outbound->maskedKey() }}</span></x-slot>
            @endif

            <p class="text-sm text-zinc-400">{{ __('Qualified leads with an email go to a sequence in your cold email tool, using your own API key. Sends, replies and bounces come back to the lead.') }}</p>

            @if (! $outbound)
                <form wire:submit="connectOutbound" class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-[12rem_1fr_auto]">
                    <flux:select wire:model="connector" :label="__('Connector')">
                        @foreach ($connectors as $key => $label)
                            <flux:select.option wire:key="connector-{{ $key }}" value="{{ $key }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="apiKey" type="password" :label="__('API key')" class="font-mono" autocomplete="off" />
                    <div class="md:pt-6">
                        <flux:button type="submit" variant="primary" icon="link">{{ __('Connect') }}</flux:button>
                    </div>
                </form>
                <p class="mt-3 text-xs text-zinc-500">{{ __('The key is checked against the tool before saving and stored encrypted.') }}</p>
            @else
                @if ($outbound->last_error)
                    <div class="mt-5 rounded-xl border border-red-500/40 bg-red-500/10 p-4 text-sm text-red-300">
                        <p class="eyebrow text-red-400">{{ __('Last error') }}</p>
                        <p class="font-mono mt-2 text-xs break-words">{{ $outbound->last_error }}</p>
                    </div>
                @endif

                <form wire:submit="saveOutbound" class="mt-6 space-y-4">
                    @if ($campaignsError)
                        <p class="text-sm text-red-400">{{ __('Could not load the campaigns: :reason', ['reason' => $campaignsError]) }}</p>
                    @endif
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-[1fr_auto]">
                        <flux:select wire:model="campaignId" :label="__('Campaign')" :placeholder="__('Pick a sequence')">
                            @foreach ($campaigns as $campaign)
                                <flux:select.option wire:key="campaign-{{ $campaign['id'] }}" value="{{ $campaign['id'] }}">{{ $campaign['name'] }}{{ $campaign['active'] ? '' : ' ('.__('paused').')' }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <div class="md:pt-6">
                            <flux:button icon="arrow-path" wire:click="refreshCampaigns" :aria-label="__('Reload campaigns')" />
                        </div>
                    </div>
                    <flux:switch wire:model="autoPush" :label="__('Send automatically when a lead is qualified')" :description="__('Only leads with an email. Each lead is sent once.')" />
                    <div class="flex flex-wrap gap-2">
                        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                        <flux:button icon="paper-airplane" wire:click="pushQualifiedLeads" :disabled="! $outbound->isReady()">{{ __('Send qualified leads now') }}</flux:button>
                    </div>
                </form>

                <div class="-mx-5 mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-white/6 px-5 pt-5">
                    <p class="text-sm text-zinc-400">
                        <span class="eyebrow mr-2">{{ __('Events webhook') }}</span>
                        @if ($outbound->webhook_id)
                            <span class="chip chip-signal">{{ __('Registered') }}</span>
                        @elseif ($webhookIsLocal)
                            <span class="text-zinc-500">{{ __('Needs a public APP_URL to receive sends and replies.') }}</span>
                        @else
                            <span class="text-zinc-500">{{ __('Not registered') }}</span>
                            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="registerOutboundWebhook" class="ml-2">{{ __('Register') }}</flux:button>
                        @endif
                    </p>
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="disconnectOutbound" :wire:confirm="__('Disconnect this connector?')">{{ __('Disconnect') }}</flux:button>
                </div>
            @endif
        </x-ui.panel>

        <x-ui.panel eyebrow="04 / {{ __('Outbound') }}" :title="__('Webhooks')">
            <x-slot name="actions"><span class="chip">X-OmniLeads-Signature · HMAC SHA-256</span></x-slot>

            <p class="text-sm text-zinc-400">{!! __('Send qualified leads to n8n, Make or your outbound sequence. Every delivery is signed with HMAC SHA-256 in the :header header.', ['header' => '<code>X-OmniLeads-Signature</code>']) !!}</p>

            @if ($revealedSecret)
                <div class="mt-5 rounded-xl border border-signal/40 bg-signal/10 p-4">
                    <p class="eyebrow text-signal">{{ __('Secret of the new webhook') }}</p>
                    <p class="mt-2 text-sm text-zinc-300">{{ __('Save it now, it will not be shown again:') }}</p>
                    <code class="font-mono mt-2 block select-all break-all rounded-lg bg-ink px-3 py-2 text-xs text-signal">{{ $revealedSecret }}</code>
                </div>
            @endif

            <form wire:submit="addWebhook" class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-[1fr_auto]">
                <flux:input wire:model="webhookUrl" label="URL" placeholder="https://n8n.yourdomain.com/webhook/omni-leads" class="font-mono" />
                <div class="md:pt-6">
                    <flux:button type="submit" variant="primary" icon="plus">{{ __('Add') }}</flux:button>
                </div>
                <div class="md:col-span-2">
                    <flux:checkbox.group wire:model="webhookEvents" :label="__('Events')" class="flex flex-wrap gap-x-6 gap-y-3">
                        @foreach ($events as $key => $label)
                            @if ($key !== 'webhook.test')
                                <div wire:key="event-{{ $key }}" class="contents"><flux:checkbox value="{{ $key }}" :label="$label" description="{{ $key }}" /></div>
                            @endif
                        @endforeach
                    </flux:checkbox.group>
                    <flux:error name="webhookEvents" />
                </div>
            </form>

            @if ($webhooks->isNotEmpty())
                <div class="-mx-5 mt-6 border-t border-white/6 px-5">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>URL</flux:table.column>
                            <flux:table.column>{{ __('Events') }}</flux:table.column>
                            <flux:table.column>{{ __('Last delivery') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($webhooks as $hook)
                                <flux:table.row :key="$hook->id">
                                    <flux:table.cell class="font-mono max-w-xs truncate text-xs text-zinc-200">{{ $hook->url }}</flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs text-zinc-400">{{ implode(', ', $hook->events) }}</flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs text-zinc-400">
                                        {{ $hook->last_delivered_at?->diffForHumans() ?? '—' }}
                                        @if ($hook->last_status)<span class="text-zinc-600">({{ $hook->last_status }})</span>@endif
                                        @if ($hook->failures_count > 0)<span class="text-red-400">{{ trans_choice(':count failure|:count failures', $hook->failures_count) }}</span>@endif
                                    </flux:table.cell>
                                    <flux:table.cell><span class="chip {{ $hook->active ? 'chip-signal' : '' }}">{{ $hook->active ? __('Active') : __('Paused') }}</span></flux:table.cell>
                                    <flux:table.cell>
                                        <div class="flex justify-end gap-1">
                                            <flux:button size="sm" variant="ghost" icon="paper-airplane" wire:click="testWebhook({{ $hook->id }})" :aria-label="__('Test')" />
                                            <flux:button size="sm" variant="ghost" :icon="$hook->active ? 'pause' : 'play'" wire:click="toggleWebhook({{ $hook->id }})" :aria-label="__('Pause/resume')" />
                                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteWebhook({{ $hook->id }})" :wire:confirm="__('Remove this webhook?')" :aria-label="__('Remove')" />
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </x-ui.panel>

        <x-ui.panel :eyebrow="__('Outbound')" :title="__('Email template')">
            <p class="text-sm text-zinc-400">{{ __('First cold email, filled for each lead and sent with it to the connector. Placeholders:') }} @foreach ($placeholders as $p)<code wire:key="email-ph-{{ $loop->index }}">{{ $p }}</code>{{ $loop->last ? '' : ', ' }}@endforeach.</p>
            @if ($messageTags)
                <p class="mt-2 text-sm text-zinc-400">{!! __('In the :connector sequence step, use :subject as the subject and :message as the body.', ['connector' => e($outboundLabel), 'subject' => '<code class="text-signal">'.e($messageTags['subject']).'</code>', 'message' => '<code class="text-signal">'.e($messageTags['message']).'</code>']) !!}</p>
            @endif
            <form wire:submit="saveEmailTemplate" class="mt-5 space-y-4">
                <flux:input wire:model="emailSubject" :label="__('Subject')" />
                <flux:textarea wire:model="emailBody" rows="8" :label="__('Message')" />
                <flux:button type="submit" variant="primary">{{ __('Save template') }}</flux:button>
            </form>
        </x-ui.panel>

        <x-ui.panel :eyebrow="__('Outbound')" :title="__('WhatsApp template')">
            <p class="text-sm text-zinc-400">{{ __('First message sent through Evolution from the lead page. Placeholders:') }} @foreach ($placeholders as $p)<code>{{ $p }}</code>{{ $loop->last ? '' : ', ' }}@endforeach.</p>
            <form wire:submit="saveTemplate" class="mt-5 space-y-4">
                <flux:textarea wire:model="whatsappTemplate" rows="5" />
                <flux:button type="submit" variant="primary">{{ __('Save template') }}</flux:button>
            </form>
        </x-ui.panel>

        <x-ui.panel :eyebrow="__('Maintenance')" :title="__('Duplicates')">
            <x-slot name="actions"><span class="chip">leads:merge-duplicates · 03:00</span></x-slot>
            <p class="text-sm text-zinc-400">{{ __('Leads sharing the same tax ID, domain, phone or email become one, keeping the oldest. Runs automatically after each search and every night.') }}</p>
            <flux:button class="mt-5" icon="arrows-pointing-in" wire:click="mergeDuplicates">{{ __('Merge duplicates now') }}</flux:button>
        </x-ui.panel>
    </div>
</div>
