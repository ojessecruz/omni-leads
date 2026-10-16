@php
    $dot = ['zinc' => 'bg-zinc-500', 'lime' => 'bg-signal', 'red' => 'bg-red-400', 'blue' => 'bg-sky-400', 'green' => 'bg-emerald-400'];
    $filtered = $search !== '' || $icp !== '' || $city !== '' || $country !== '' || $driver !== '' || $minScore > 0;
@endphp

<div>
    <x-ui.page-header
        eyebrow="02 / {{ __('Leads') }}"
        :title="__('Leads')"
        :accent="trans_choice(':count result.|:count results.', $leads->total(), ['count' => number_format($leads->total(), 0, ',', '.')])">
        <flux:button size="sm" wire:click="export" icon="arrow-down-tray" :disabled="$leads->total() === 0">
            {{ __('Export CSV') }}{{ $selected ? ' ('.count($selected).')' : '' }}
        </flux:button>
    </x-ui.page-header>

    {{-- Status tabs: the filter used most, always one click away. Counts follow the other filters. --}}
    <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" data-reveal>
        <div class="flex w-max gap-1 rounded-xl border border-white/8 p-1" role="tablist" aria-label="{{ __('Status') }}">
            @foreach (['' => [__('All'), null], ...collect($statuses)->mapWithKeys(fn ($s) => [$s->value => [$s->label(), $s->color()]])->all()] as $value => [$label, $color])
                @php($count = $value === '' ? $statusCounts->sum() : ($statusCounts[$value] ?? 0))
                <button
                    type="button"
                    role="tab"
                    wire:key="status-tab-{{ $value ?: 'all' }}"
                    wire:click="$set('status', '{{ $value }}')"
                    aria-selected="{{ $status === $value ? 'true' : 'false' }}"
                    @class([
                        'flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm transition',
                        'bg-white/10 text-white' => $status === $value,
                        'text-zinc-400 hover:bg-white/5 hover:text-white' => $status !== $value,
                    ])
                >
                    @if ($color)<span class="size-1.5 rounded-full {{ $dot[$color] ?? 'bg-zinc-500' }}"></span>@endif
                    {{ $label }}
                    <span class="font-mono text-2xs text-zinc-500">{{ number_format($count, 0, ',', '.') }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="relative z-20 mt-3 flex flex-wrap items-center gap-2" data-reveal>
        <flux:input wire:model.live.debounce.400ms="search" :placeholder="__('Search name, domain, email, city…')" icon="magnifying-glass" class="min-w-56 flex-1" clearable />
        <x-ui.combobox model="icp" :options="$icps" :all-label="__('All ICPs')" icon="funnel" class="w-full sm:w-52" />
        <x-ui.combobox model="city" :options="$cities" :all-label="__('All cities')" icon="map-pin" class="w-full sm:w-52" />
        @if ($countries->count() > 1)
            <x-ui.combobox model="country" :options="$countries->mapWithKeys(fn ($c) => [$c => ['label' => \App\Support\Countries::name($c)]])" :all-label="__('All countries')" icon="globe-alt" class="w-full sm:w-44" />
        @endif
        @if ($drivers->count() > 1 || $driver !== '')
            <x-ui.combobox model="driver" :options="$drivers" :all-label="__('Any source')" icon="circle-stack" class="w-full sm:w-44" />
        @endif
        <x-ui.combobox model="sort" :options="['score' => ['label' => __('Highest score')], 'recent' => ['label' => __('Newest')], 'name' => ['label' => __('Name (A–Z)')]]" icon="arrows-up-down" class="w-full sm:w-44" />
        @if ($filtered)
            <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
        @endif
    </div>

    <div class="mt-4" data-reveal>
    @if ($leads->isEmpty())
        <x-ui.empty code="leads · 0 rows" :title="__('No leads found')">
            {{ __('Run a search on an ICP or adjust the filters.') }}
        </x-ui.empty>
    @else
        <x-ui.panel :padding="false">
            <div class="px-4">
            <flux:table :paginate="$leads">
                <flux:table.columns>
                    <flux:table.column class="w-8"><flux:checkbox wire:model.live="selectAll" :aria-label="__('Select all on this page')" /></flux:table.column>
                    <flux:table.column class="w-16">{{ __('Score') }}</flux:table.column>
                    <flux:table.column>{{ __('Company') }}</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">{{ __('Contact') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">{{ __('Added') }}</flux:table.column>
                    <flux:table.column class="w-28"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($leads as $lead)
                        @php($conversation = $lead->whatsappConversations->first())
                        <flux:table.row :key="$lead->id" class="group">
                            <flux:table.cell><flux:checkbox wire:model.live="selected" value="{{ $lead->id }}" :aria-label="__('Select :name', ['name' => $lead->displayName()])" /></flux:table.cell>

                            <flux:table.cell>
                                <div class="w-11" title="{{ __('Qualification score') }}">
                                    <span @class(['font-mono text-sm', 'text-signal' => $lead->score >= $qualifiedAt, 'text-white' => $lead->score < $qualifiedAt])>{{ $lead->score }}</span>
                                    <div class="mt-1 h-1 rounded-full bg-white/8">
                                        <div @class(['h-1 rounded-full', 'bg-signal' => $lead->score >= $qualifiedAt, 'bg-zinc-500' => $lead->score < $qualifiedAt]) style="width: {{ max(4, min(100, $lead->score)) }}%"></div>
                                    </div>
                                </div>
                            </flux:table.cell>

                            <flux:table.cell class="max-w-0 w-full">
                                <a href="{{ route('leads.show', $lead) }}" wire:navigate class="block truncate font-medium text-white hover:text-signal" title="{{ $lead->displayName() }}">{{ $lead->displayName() }}</a>
                                <div class="mt-0.5 truncate text-xs text-zinc-500">
                                    {{ collect([$lead->city, $lead->icp->name])->filter()->implode(' · ') }}
                                    @if ($lead->domain)
                                        · <a href="{{ $lead->websiteUrl() }}" target="_blank" rel="noopener" class="font-mono hover:text-white">{{ $lead->domain }}</a>
                                    @endif
                                </div>
                            </flux:table.cell>

                            <flux:table.cell class="hidden md:table-cell">
                                <div class="flex flex-col gap-0.5 font-mono text-xs">
                                    @if ($lead->phone)
                                        <span @class(['flex items-center gap-1.5', 'text-zinc-300' => $lead->whatsapp_valid === true, 'text-zinc-500' => $lead->whatsapp_valid !== true])>
                                            <flux:icon.chat-bubble-oval-left-ellipsis variant="micro" @class(['text-emerald-400' => $lead->whatsapp_valid === true, 'text-zinc-600' => $lead->whatsapp_valid !== true]) />
                                            +{{ $lead->phone }}
                                        </span>
                                    @endif
                                    @if ($lead->email)
                                        <span class="flex max-w-56 items-center gap-1.5 truncate text-zinc-400">
                                            <flux:icon.envelope variant="micro" class="shrink-0 text-zinc-500" />
                                            <span class="truncate">{{ $lead->email }}</span>
                                        </span>
                                    @endif
                                    @if (! $lead->email && ! $lead->phone)<span class="text-zinc-600">—</span>@endif
                                </div>
                            </flux:table.cell>

                            <flux:table.cell><flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge></flux:table.cell>

                            <flux:table.cell class="hidden font-mono text-2xs text-zinc-500 lg:table-cell" title="{{ $lead->created_at->format('d/m/Y H:i') }}">{{ $lead->created_at->diffForHumans(short: true) }}</flux:table.cell>

                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-0.5 opacity-60 transition group-hover:opacity-100">
                                    @if ($conversation)
                                        <flux:button size="xs" variant="ghost" icon="chat-bubble-left-right" :href="route('conversations.index', ['c' => $conversation->id])" wire:navigate :tooltip="__('Open conversation')" />
                                    @elseif ($canWhatsApp && $lead->phone && $lead->whatsapp_valid !== false)
                                        <flux:button size="xs" variant="ghost" icon="paper-airplane" wire:click="openWhatsApp({{ $lead->id }})" :tooltip="__('Send WhatsApp')" />
                                    @endif
                                    @if (! in_array($lead->status, [\App\Enums\LeadStatus::Contacted, \App\Enums\LeadStatus::Replied], true))
                                        <flux:button size="xs" variant="ghost" icon="check" wire:click="markOne({{ $lead->id }}, 'contacted')" :tooltip="__('Mark contacted')" />
                                    @endif
                                    @if ($lead->status !== \App\Enums\LeadStatus::Discarded)
                                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="markOne({{ $lead->id }}, 'discarded')" :tooltip="__('Discard')" />
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            </div>
        </x-ui.panel>
    @endif
    </div>

    <flux:modal name="lead-whatsapp" class="w-full max-w-lg">
        @if ($whatsappLead)
            <form wire:submit="sendWhatsApp" class="space-y-5">
                <div>
                    <p class="eyebrow">WhatsApp</p>
                    <h2 class="display mt-1 text-3xl font-semibold text-white">{{ __('Send WhatsApp') }}</h2>
                    <p class="mt-2 text-sm text-zinc-400">
                        {{ __('To') }} <span class="text-zinc-200">{{ $whatsappLead->displayName() }}</span>
                        · <span class="font-mono text-zinc-200">+{{ $whatsappLead->phone }}</span>{{ $whatsappLead->whatsapp_valid === true ? ' ('.__('WhatsApp validated').')' : '' }}.
                        {{ __('The template lives in the project settings.') }}
                    </p>
                </div>
                <flux:textarea wire:model="whatsappText" rows="7" :label="__('Message')" />
                <div class="flex items-center justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    {{-- Bulk actions float at the bottom while something is selected. --}}
    @if ($selected)
        <div class="fixed inset-x-0 bottom-4 z-30 flex justify-center px-4">
            <div class="flex flex-wrap items-center gap-2 rounded-xl border border-white/10 bg-panel px-3 py-2 shadow-glow">
                <span class="chip chip-signal">{{ __(':count selected', ['count' => count($selected)]) }}</span>
                <flux:button size="sm" wire:click="markAs('contacted')" icon="check">{{ __('Mark contacted') }}</flux:button>
                <flux:button size="sm" variant="danger" wire:click="markAs('discarded')" icon="trash">{{ __('Discard') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="export" icon="arrow-down-tray">{{ __('Export CSV') }}</flux:button>
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('selected', [])" :aria-label="__('Clear selection')" />
            </div>
        </div>
    @endif
</div>
