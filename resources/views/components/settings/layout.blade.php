<div class="grid grid-cols-1 gap-8 md:grid-cols-12" data-reveal>
    <nav class="font-mono flex flex-wrap gap-x-6 gap-y-2 text-2xs uppercase tracking-label md:col-span-3 md:flex-col md:gap-y-3" aria-label="{{ __('Settings') }}">
        @php
            $items = [['profile.edit', __('Profile'), '01'], ['security.edit', __('Security'), '02'], ['appearance.edit', __('Appearance'), '03'], ['integrations.edit', __('Integrations'), '04']];
            if (auth()->user()?->can('invite-users')) {
                $items[] = ['invitations.index', __('Invitations'), '05'];
            }
        @endphp
        @foreach ($items as [$route, $label, $n])
            <a href="{{ route($route) }}" wire:navigate class="flex items-center gap-2 {{ request()->routeIs($route) ? 'text-white' : 'text-zinc-500 hover:text-white' }}">
                <span class="{{ request()->routeIs($route) ? 'text-signal' : 'text-zinc-700' }}">{{ $n }}</span>{{ $label }}
            </a>
        @endforeach
    </nav>

    <div class="md:col-span-9">
        <div class="panel p-6 md:p-8">
            <h2 class="display text-3xl font-semibold text-white">{{ $heading ?? '' }}</h2>
            <p class="mt-2 text-sm text-zinc-400">{{ $subheading ?? '' }}</p>
            <div @class(['mt-6 w-full', 'max-w-lg' => ! ($wide ?? false)])>
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
