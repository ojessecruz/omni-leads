<div>
    <x-ui.page-header
        eyebrow="01 / {{ __('Projects') }}"
        :title="__('Projects')"
        :accent="__('one product, many ICPs.')"
        :description="__('Each project has its product context and one or more ICPs.')">
        <flux:modal.trigger name="new-project">
            <flux:button variant="primary" icon="plus">{{ __('New project') }}</flux:button>
        </flux:modal.trigger>
    </x-ui.page-header>

    @if ($projects->isEmpty())
        <x-ui.empty code="step 01 / 04" :title="__('No projects yet')" data-reveal>
            {{ __('Create your first project and define the ICP in the wizard. Lead discovery starts from there.') }}
            <x-slot name="actions">
                <flux:modal.trigger name="new-project">
                    <flux:button variant="primary" icon="plus">{{ __('New project') }}</flux:button>
                </flux:modal.trigger>
            </x-slot>
        </x-ui.empty>
    @else
        <div class="cell-grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3" data-reveal>
            @foreach ($projects as $project)
                <a wire:key="project-{{ $project->id }}" href="{{ route('projects.show', $project) }}" wire:navigate class="group flex min-h-56 flex-col p-6">
                    <p class="font-mono text-2xs text-zinc-600 group-hover:text-signal">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</p>
                    <h2 class="mt-4 text-2xl font-semibold tracking-tight text-white">{{ $project->name }}</h2>
                    <p class="mt-2 line-clamp-2 text-sm text-zinc-400">{{ $project->product_description ?: __('No product context yet.') }}</p>
                    <div class="font-mono mt-auto flex gap-4 pt-6 text-2xs text-zinc-500">
                        <span><span class="text-white">{{ $project->icps_count }}</span> {{ trans_choice('ICP|ICPs', $project->icps_count) }}</span>
                        <span><span class="text-white">{{ number_format($project->leads_count, 0, ',', '.') }}</span> {{ __('leads') }}</span>
                    </div>
                </a>
            @endforeach
            @for ($i = $projects->count(); $i % 3 !== 0; $i++)<div class="hidden lg:block"></div>@endfor
            @if ($projects->count() % 2 !== 0)<div class="hidden sm:block lg:hidden"></div>@endif
        </div>
    @endif

    <flux:modal name="new-project" class="w-full max-w-md">
        <form wire:submit="create" class="space-y-6">
            <div>
                <p class="eyebrow mb-3">{{ __('New project') }}</p>
                <h2 class="display text-3xl font-semibold text-white">{{ __('Name it.') }}</h2>
                <p class="mt-2 text-sm text-zinc-400">{{ __('Give it a name. The product context comes in the first step of the wizard.') }}</p>
            </div>
            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. Scheduling for clinics')" autofocus />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create and define ICP') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
