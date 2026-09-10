<div>
    <x-ui.page-header
        eyebrow="02 / {{ __('Leads') }}"
        :title="__('Leads')"
        :accent="trans_choice(':count result.|:count results.', $leads->total(), ['count' => number_format($leads->total(), 0, ',', '.')])">
        @if ($selected)
            <span class="chip chip-signal">{{ __(':count selected', ['count' => count($selected)]) }}</span>
            <flux:button size="sm" wire:click="markAs('contacted')" icon="chat-bubble-left">{{ __('Mark contacted') }}</flux:button>
            <flux:button size="sm" variant="danger" wire:click="markAs('discarded')" icon="trash">{{ __('Discard') }}</flux:button>
        @endif
        <flux:button size="sm" wire:click="export" icon="arrow-down-tray" :disabled="$leads->total() === 0">
            {{ __('Export CSV') }}{{ $selected ? ' ('.count($selected).')' : '' }}
        </flux:button>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-6" data-reveal>
        <flux:input wire:model.live.debounce.400ms="search" :placeholder="__('Search name, domain, email, city…')" icon="magnifying-glass" class="md:col-span-2" clearable />
        <flux:select wire:model.live="icp" :placeholder="__('All ICPs')">
            @foreach ($icps as $item)
                <flux:select.option value="{{ $item->id }}">{{ $item->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" :placeholder="__('All statuses')">
            @foreach ($statuses as $s)
                <flux:select.option value="{{ $s->value }}">{{ $s->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="country" :placeholder="__('All countries')">
            @foreach ($countries as $c)
                <flux:select.option value="{{ $c }}">{{ \App\Support\Countries::name($c) }}</flux:select.option>
            @endforeach
        </flux:select>
        <div class="flex gap-2">
            <flux:select wire:model.live="driver" class="flex-1" :placeholder="__('Any source')">
                @foreach ($drivers as $d)
                    <flux:select.option value="{{ $d }}">{{ $d }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button variant="ghost" icon="x-mark" wire:click="clearFilters" :aria-label="__('Clear filters')" />
        </div>
    </div>

    <div class="mt-6" data-reveal>
    @if ($leads->isEmpty())
        <x-ui.empty code="leads · 0 rows" :title="__('No leads found')">
            {{ __('Run a search on an ICP or adjust the filters.') }}
        </x-ui.empty>
    @else
        <x-ui.panel :padding="false">
            <div class="panel-head">
                <span class="font-mono text-2xs text-zinc-500">leads.log — {{ collect([$icp ? 'icp:'.$icp : null, $status ?: null, $country ?: null, $driver ?: null])->filter()->implode(' · ') ?: __('all') }}</span>
                <span class="font-mono text-2xs text-zinc-500">{{ __('page :n', ['n' => $leads->currentPage()]) }}</span>
            </div>
            <div class="px-5">
            <flux:table :paginate="$leads">
                <flux:table.columns>
                    <flux:table.column class="w-10"><flux:checkbox wire:model.live="selectAll" :aria-label="__('Select all on this page')" /></flux:table.column>
                    <flux:table.column>{{ __('Company') }}</flux:table.column>
                    <flux:table.column>{{ __('Contact') }}</flux:table.column>
                    <flux:table.column>{{ __('Location') }}</flux:table.column>
                    <flux:table.column>{{ __('ICP') }}</flux:table.column>
                    <flux:table.column>{{ __('Source') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Score') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($leads as $lead)
                        <flux:table.row :key="$lead->id">
                            <flux:table.cell><flux:checkbox wire:model.live="selected" value="{{ $lead->id }}" :aria-label="__('Select :name', ['name' => $lead->displayName()])" /></flux:table.cell>
                            <flux:table.cell>
                                <a href="{{ route('leads.show', $lead) }}" wire:navigate class="font-medium text-white hover:text-signal">{{ $lead->displayName() }}</a>
                                @if ($lead->domain)
                                    <div class="font-mono text-2xs text-zinc-500">{{ $lead->domain }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="font-mono text-xs text-zinc-300">
                                @if ($lead->email)<div>{{ $lead->email }}</div>@endif
                                @if ($lead->phone)<div class="text-zinc-400">+{{ $lead->phone }} @if ($lead->whatsapp_valid === true)<span class="text-signal">✓</span>@endif</div>@endif
                                @if (! $lead->email && ! $lead->phone)<span class="text-zinc-600">—</span>@endif
                            </flux:table.cell>
                            <flux:table.cell class="text-xs text-zinc-400">{{ $lead->city ? $lead->city.', ' : '' }}{{ $lead->country }}</flux:table.cell>
                            <flux:table.cell class="max-w-40 truncate text-xs text-zinc-400">{{ $lead->icp->name }}</flux:table.cell>
                            <flux:table.cell>
                                <span class="flex flex-wrap gap-1">
                                @foreach ($lead->sources->pluck('driver')->unique() as $d)
                                    <span class="chip">{{ $d }}</span>
                                @endforeach
                                </span>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="font-mono {{ $lead->status === \App\Enums\LeadStatus::Qualified ? 'text-signal' : 'text-white' }}">{{ $lead->score }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            </div>
        </x-ui.panel>
    @endif
    </div>
</div>
