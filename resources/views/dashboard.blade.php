<x-layouts::app :title="__('Dashboard')">
    @php
        $user = auth()->user();
        $projects = $user->projects()->count();
        $icps = \App\Models\Icp::whereHas('project', fn ($q) => $q->where('user_id', $user->id))->count();
        $leads = \App\Models\Lead::whereHas('project', fn ($q) => $q->where('user_id', $user->id));
        $total = (clone $leads)->count();
        $qualified = (clone $leads)->where('status', \App\Enums\LeadStatus::Qualified)->count();
        $contacted = (clone $leads)->whereIn('status', [\App\Enums\LeadStatus::Contacted, \App\Enums\LeadStatus::Replied])->count();
        $recent = (clone $leads)->with('icp')->latest()->limit(8)->get();
        $running = \App\Models\SearchJob::whereHas('icp.project', fn ($q) => $q->where('user_id', $user->id))->where('status', \App\Enums\SearchJobStatus::Running)->count();
        $fmt = fn (int $n) => number_format($n, 0, ',', '.');
    @endphp

    <x-ui.page-header
        eyebrow="00 / {{ __('Dashboard') }}"
        :title="__('Hi, :name', ['name' => Str::before($user->name, ' ')])"
        :accent="$total > 0 ? __('what moved.') : __('let’s start.')"
        :description="__('Overview of your projects and leads.')">
        @if ($running > 0)
            <span class="chip chip-signal"><span class="inline-block size-1.5 rounded-full bg-signal pulse"></span>{{ trans_choice(':count search running|:count searches running', $running) }}</span>
        @endif
        <flux:button :href="route('projects.index')" wire:navigate variant="primary" icon="plus">{{ __('New project') }}</flux:button>
    </x-ui.page-header>

    <livewire:dashboard.onboarding />

    <div class="cell-grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4" data-reveal>
        <x-ui.stat :label="__('Projects')" :value="$fmt($projects)" :href="route('projects.index')" hint="projects" />
        <x-ui.stat :label="__('ICPs')" :value="$fmt($icps)" :href="route('projects.index')" hint="icps.criteria → jsonb" />
        <x-ui.stat :label="__('Leads')" :value="$fmt($total)" :href="route('leads.index')" hint="leads · dedup by project" />
        <x-ui.stat :label="__('Qualified')" :value="$fmt($qualified)" :href="route('leads.index', ['status' => 'qualified'])" hint="score ≥ {{ config('lead-scoring.qualified_threshold') }}" signal />
    </div>

    @if ($projects === 0)
        {{-- The first steps panel covers this until it is dismissed. --}}
        @if ($user->onboarding_dismissed_at)
            <x-ui.empty class="mt-6" :title="__('Start by creating a project')" data-reveal>
                {{ __('Describe your product, define the ICP in the wizard and run the first search.') }}
                <x-slot name="actions">
                    <flux:button :href="route('projects.index')" wire:navigate variant="primary">{{ __('Go to projects') }}</flux:button>
                </x-slot>
            </x-ui.empty>
        @endif
    @else
        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12" data-reveal>
            <x-ui.panel class="lg:col-span-8" :eyebrow="__('Latest')" :title="__('Recently discovered')" :padding="false">
                <x-slot name="actions">
                    <a href="{{ route('leads.index') }}" wire:navigate class="font-mono text-2xs uppercase tracking-label text-zinc-500 hover:text-white">{{ __('All leads') }} →</a>
                </x-slot>
                @if ($recent->isEmpty())
                    <p class="p-5 text-sm text-zinc-500">{{ __('No leads yet.') }}</p>
                @else
                    <ul class="font-mono divide-y divide-white/6 text-xs">
                        @foreach ($recent as $lead)
                            <li>
                                <a href="{{ route('leads.show', $lead) }}" wire:navigate class="flex items-center gap-3 px-5 py-2.5 hover:bg-white/3">
                                    <span class="w-14 shrink-0 text-zinc-600">{{ $lead->created_at->local()->format('H:i') }}</span>
                                    <span class="min-w-0 flex-1 truncate text-zinc-200">{{ $lead->displayName() }}</span>
                                    <span class="hidden max-w-40 truncate text-zinc-500 md:block">{{ $lead->icp->name }}</span>
                                    <span class="w-10 text-right {{ $lead->status === \App\Enums\LeadStatus::Qualified ? 'text-signal' : 'text-zinc-400' }}">{{ $lead->score }}</span>
                                    <span class="w-20 text-right text-zinc-500">{{ $lead->status->label() }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.panel>

            <x-ui.panel class="lg:col-span-4" :eyebrow="__('Funnel')" :title="__('Across all projects')">
                @foreach ([[__('Discovered'), $total, 'text-zinc-200'], [__('Qualified'), $qualified, 'text-signal'], [__('Contacted'), $contacted, 'text-zinc-200']] as [$label, $value, $color])
                    <div class="mb-4 last:mb-0">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-zinc-400">{{ $label }}</span>
                            <span class="font-mono {{ $color }}">{{ $fmt($value) }}</span>
                        </div>
                        <div class="mt-2 h-1 w-full rounded-full bg-white/6">
                            <div class="h-1 rounded-full {{ $loop->index === 1 ? 'bg-signal' : 'bg-zinc-400' }}" style="width: {{ $total > 0 ? round($value / $total * 100) : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </x-ui.panel>
        </div>
    @endif
</x-layouts::app>
