<div>
    <x-ui.page-header :title="$project->name" :accent="trans_choice(':count ICP.|:count ICPs.', $icps->count())">
        <x-slot name="crumbs">
            <x-ui.crumb :href="route('projects.index')">{{ __('Projects') }}</x-ui.crumb>
            <x-ui.crumb>{{ $project->name }}</x-ui.crumb>
        </x-slot>
        <flux:button :href="route('projects.settings', $project)" wire:navigate icon="cog-6-tooth">{{ __('Settings') }}</flux:button>
        <flux:button :href="route('icps.create', $project)" wire:navigate variant="primary" icon="plus">
            {{ $draft ? __('Resume ICP draft') : __('New ICP') }}
        </flux:button>
    </x-ui.page-header>

    @if ($needsPlacesKey)
        <x-integrations.missing-places-key class="mb-6" />
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12" data-reveal>
        <x-ui.panel class="lg:col-span-12" :eyebrow="__('Context')" :title="__('Product')">
            <dl class="kv grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.kv :label="__('Product')">{{ $project->product_description ?: '—' }}</x-ui.kv>
                <x-ui.kv :label="__('Problem it solves')">{{ $project->problem ?: '—' }}</x-ui.kv>
                <x-ui.kv :label="__('Current customers')">{{ $project->current_customers ?: '—' }}</x-ui.kv>
                <x-ui.kv :label="__('Average ticket')"><span class="font-mono">{{ $project->avg_ticket ?: '—' }}</span></x-ui.kv>
            </dl>
        </x-ui.panel>
    </div>

    <div class="mt-10" data-reveal>
        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <p class="eyebrow">02 / {{ __('ICPs') }}</p>
                <h2 class="display mt-3 text-3xl font-semibold text-white md:text-4xl">{{ __('Who you sell to.') }}</h2>
            </div>
        </div>

        @if ($icps->isEmpty())
            <x-ui.empty code="icps.criteria → jsonb" :title="__('No ICP defined.')">
                {{ __('No ICP defined. Use the wizard to create the first one.') }}
                <x-slot name="actions">
                    <flux:button :href="route('icps.create', $project)" wire:navigate variant="primary" icon="plus">{{ __('New ICP') }}</flux:button>
                </x-slot>
            </x-ui.empty>
        @else
            <x-ui.panel :padding="false">
                <div class="px-5">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Keywords') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Searches') }}</flux:table.column>
                        <flux:table.column>{{ __('Re-run') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Leads') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($icps as $icp)
                            <flux:table.row :key="$icp->id">
                                <flux:table.cell>
                                    <a href="{{ route('icps.show', $icp) }}" wire:navigate class="font-medium text-white hover:text-signal">{{ $icp->name }}</a>
                                </flux:table.cell>
                                <flux:table.cell><span class="chip {{ $icp->status === \App\Enums\IcpStatus::Active ? 'chip-signal' : '' }}">{{ $icp->status->label() }}</span></flux:table.cell>
                                <flux:table.cell class="max-w-xs truncate text-zinc-400">{{ implode(', ', $icp->criteria_object->keywords) }}</flux:table.cell>
                                <flux:table.cell align="end" class="font-mono">{{ $icp->search_jobs_count }}</flux:table.cell>
                                <flux:table.cell class="font-mono text-xs text-zinc-500">{{ $icp->rerun_every_days ? __('every :days days', ['days' => $icp->rerun_every_days]) : '—' }}</flux:table.cell>
                                <flux:table.cell align="end" class="font-mono text-white">{{ number_format($icp->leads_count, 0, ',', '.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
                </div>
            </x-ui.panel>
        @endif
    </div>
</div>
