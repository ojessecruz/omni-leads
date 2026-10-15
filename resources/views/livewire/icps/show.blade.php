<div @if ($active) wire:poll.5s.visible @endif>
    <x-ui.page-header :title="$icp->name">
        <x-slot name="crumbs">
            <x-ui.crumb :href="route('projects.index')">{{ __('Projects') }}</x-ui.crumb>
            <x-ui.crumb :href="route('projects.show', $icp->project)">{{ $icp->project->name }}</x-ui.crumb>
            <x-ui.crumb>{{ $icp->name }}</x-ui.crumb>
        </x-slot>
        <span class="chip {{ $icp->status === \App\Enums\IcpStatus::Active ? 'chip-signal' : '' }}">{{ $icp->status->label() }}</span>
        <flux:button :href="route('leads.index', ['icp' => $icp->id])" wire:navigate icon="users">
            {{ __(':count leads', ['count' => number_format($leadsCount, 0, ',', '.')]) }}
        </flux:button>
        <flux:dropdown>
            <flux:button icon="ellipsis-horizontal" :aria-label="__('More actions')" />
            <flux:menu>
                <flux:menu.item icon="sparkles" wire:click="enrichAll(false)">{{ __('Enrich pending leads') }}</flux:menu.item>
                <flux:menu.item icon="arrow-path" wire:click="enrichAll(true)" :wire:confirm="__('Re-run enrichment for all leads? This may spend Hunter credits.')">{{ __('Re-enrich all') }}</flux:menu.item>
                <flux:menu.separator />
                <flux:menu.item icon="calculator" wire:click="rescoreAll">{{ __('Recalculate scores') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
        <flux:button variant="primary" icon="play" wire:click="runSearch" :disabled="$active">
            {{ $active ? __('Searching…') : __('Run search') }}
        </flux:button>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12" data-reveal>
        <div class="space-y-6 lg:col-span-8">
            <x-ui.panel eyebrow="01 / {{ __('Criteria') }}" :title="__('ICP is data, not code.')">
                <x-slot name="actions"><span class="chip">icps.criteria → jsonb</span></x-slot>
                <dl class="kv grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.kv :label="__('Segment')">{{ $criteria->industry ?? '—' }}</x-ui.kv>
                    <x-ui.kv :label="__('Size')"><span class="font-mono">{{ $criteria->companySize?->value ?? __('Any') }}</span></x-ui.kv>
                    <x-ui.kv class="sm:col-span-2" :label="__('Keywords')">
                        <span class="flex flex-wrap gap-1.5">@foreach ($criteria->keywords as $k)<span class="chip">{{ $k }}</span>@endforeach</span>
                    </x-ui.kv>
                    <x-ui.kv :label="__('Countries')">
                        <span class="flex flex-wrap gap-1.5">@foreach ($criteria->countries as $c)<span class="chip">{{ \App\Support\Countries::name($c) }}</span>@endforeach</span>
                    </x-ui.kv>
                    <x-ui.kv :label="__('Regions')">
                        <span class="flex flex-wrap gap-1.5">@forelse ($criteria->regions as $r)<span class="chip">{{ $r }}</span>@empty <span class="text-zinc-500">{{ $criteria->areas ? '—' : __('Whole country') }}</span> @endforelse</span>
                    </x-ui.kv>
                    @if ($criteria->areas)
                        <x-ui.kv class="sm:col-span-2" :label="__('Map areas')">
                            <span class="flex flex-wrap gap-1.5">@foreach ($criteria->areas as $area)<span class="chip">{{ $area->displayName() }}</span>@endforeach</span>
                        </x-ui.kv>
                    @endif
                    <x-ui.kv :label="__('Job titles')">{{ $criteria->jobTitles ? implode(', ', $criteria->jobTitles) : '—' }}</x-ui.kv>
                    <x-ui.kv :label="__('Signals')">{{ $criteria->hasSignals() ? collect([$criteria->hasWebsite ? __('own website') : null, $criteria->minReviews !== null ? __('≥ :n reviews', ['n' => $criteria->minReviews]) : null, $criteria->minRating !== null ? __('rating ≥ :n', ['n' => $criteria->minRating]) : null])->filter()->implode(' · ') : '—' }}</x-ui.kv>
                </dl>
            </x-ui.panel>

            <x-ui.panel eyebrow="02 / {{ __('Discovery') }}" :title="__('Searches')" :padding="false">
                <x-slot name="actions">
                    @if ($active)
                        <span class="chip chip-signal"><span class="inline-block size-1.5 rounded-full bg-signal pulse"></span>{{ __('live') }}</span>
                    @endif
                    <flux:select wire:change="setRerun($event.target.value)" size="sm" class="w-44">
                        <flux:select.option value="" :selected="$icp->rerun_every_days === null">{{ __('Never') }}</flux:select.option>
                        @foreach (\App\Models\Icp::RERUN_INTERVALS as $d)
                            <flux:select.option value="{{ $d }}" :selected="$icp->rerun_every_days === $d">{{ __('Every :days days', ['days' => $d]) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </x-slot>

                @if ($icp->next_run_at)
                    <p class="font-mono border-b border-white/6 px-5 py-2 text-2xs text-zinc-500">icps:rerun-due · {{ __('next :when', ['when' => $icp->next_run_at->diffForHumans()]) }}</p>
                @endif

                @if ($searchJobs->isEmpty())
                    <p class="p-5 text-sm text-zinc-500">{!! __('No search has run yet. Click <strong>Run search</strong>.') !!}</p>
                @else
                    <div class="px-5">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Source') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Queries') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Req.') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Results') }}</flux:table.column>
                            <flux:table.column>{{ __('Post-filter') }}</flux:table.column>
                            <flux:table.column>{{ __('Started') }}</flux:table.column>
                            <flux:table.column>{{ __('Duration') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($searchJobs as $job)
                                <flux:table.row :key="$job->id">
                                    <flux:table.cell class="font-mono text-xs text-zinc-200">{{ $job->driver }}</flux:table.cell>
                                    <flux:table.cell>
                                        <span class="chip {{ match ($job->status) { \App\Enums\SearchJobStatus::Done => 'chip-signal', \App\Enums\SearchJobStatus::Failed => 'border-red-500/40 text-red-400', default => '' } }}">
                                            @if ($job->status === \App\Enums\SearchJobStatus::Running)<span class="inline-block size-1.5 rounded-full bg-amber-400 pulse-amber"></span>@endif
                                            {{ $job->status->label() }}
                                        </span>
                                        @if ($job->error)
                                            <flux:tooltip :content="$job->error"><button type="button" class="ml-1 inline-flex align-middle" aria-label="{{ __('Error details') }}"><flux:icon.exclamation-circle class="size-4 text-red-400" /></button></flux:tooltip>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="font-mono">{{ count($job->params['queries'] ?? []) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="font-mono">{{ number_format($job->requests_count, 0, ',', '.') }}</flux:table.cell>
                                    <flux:table.cell align="end" class="font-mono text-white">{{ number_format($job->results_count, 0, ',', '.') }}</flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs text-zinc-500">{{ implode(', ', $job->params['unsupported'] ?? []) ?: '—' }}</flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs text-zinc-400">{{ $job->started_at?->diffForHumans() ?? '—' }}</flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs text-zinc-400">{{ $job->started_at && $job->finished_at ? $job->started_at->diffForHumans($job->finished_at, true) : '—' }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                    </div>
                @endif
            </x-ui.panel>
        </div>

        <div class="space-y-6 lg:col-span-4">
            <x-ui.panel eyebrow="03 / {{ __('Score') }}" :title="__('Funnel')">
                @foreach ([[__('Discovered'), $funnel['discovered'], false], [__('Enriched'), $funnel['enriched'], false], [__('Qualified'), $funnel['qualified'], true], [__('Contacted'), $funnel['contacted'], false]] as [$label, $value, $hot])
                    <div class="mb-4 last:mb-0">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-zinc-400">{{ $label }}</span>
                            <span class="font-mono {{ $hot ? 'text-signal' : 'text-zinc-200' }}">{{ number_format($value, 0, ',', '.') }}</span>
                        </div>
                        <div class="mt-2 h-1 w-full rounded-full bg-white/6">
                            <div class="h-1 rounded-full {{ $hot ? 'bg-signal' : 'bg-zinc-400' }}" style="width: {{ $funnel['discovered'] > 0 ? round($value / $funnel['discovered'] * 100) : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </x-ui.panel>

            <x-ui.panel :title="__('Leads by source')">
                <ul class="font-mono space-y-2 text-xs">
                    @forelse ($bySource as $driver => $total)
                        <li class="flex items-center justify-between"><span class="text-zinc-400">{{ $driver }}</span><span class="text-white">{{ number_format($total, 0, ',', '.') }}</span></li>
                    @empty
                        <li class="text-zinc-500">{{ __('No leads yet.') }}</li>
                    @endforelse
                </ul>
            </x-ui.panel>

            <x-ui.panel :title="__('Estimated cost')">
                <x-slot name="actions"><span class="font-mono text-sm text-white">US$ {{ number_format($cost['total'], 2) }}</span></x-slot>
                <ul class="font-mono space-y-2 text-xs">
                    @forelse ($cost['items'] as $item)
                        <li class="flex items-center justify-between text-zinc-400">
                            <span>{{ $item['source'] }} <span class="text-zinc-600">· {{ number_format($item['requests'], 0, ',', '.') }} req</span></span>
                            <span class="text-zinc-200">US$ {{ number_format($item['cost'], 2) }}</span>
                        </li>
                    @empty
                        <li class="text-zinc-500">{{ __('No requests recorded.') }}</li>
                    @endforelse
                </ul>
                <p class="mt-4 text-xs text-zinc-500">{!! __('Unit prices in :a and :b.', ['a' => '<code>config/lead-sources.php</code>', 'b' => '<code>config/enrichment.php</code>']) !!}</p>
            </x-ui.panel>

            <x-ui.panel :title="__('Leads by country')">
                <ul class="space-y-2 text-sm">
                    @forelse ($byCountry as $country => $total)
                        <li class="flex items-center justify-between"><span class="text-zinc-400">{{ \App\Support\Countries::name($country) }}</span><span class="font-mono text-white">{{ number_format($total, 0, ',', '.') }}</span></li>
                    @empty
                        <li class="text-zinc-500">{{ __('No leads yet.') }}</li>
                    @endforelse
                </ul>
            </x-ui.panel>
        </div>
    </div>
</div>
