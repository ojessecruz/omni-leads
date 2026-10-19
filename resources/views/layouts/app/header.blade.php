<!DOCTYPE html>
@php
    $theme = auth()->user()->theme;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark"
      data-theme="{{ $theme === \App\Enums\Theme::Light ? 'light' : 'dark' }}"
      @if ($theme === \App\Enums\Theme::System) data-theme-system @endif>
    <head>
        @include('partials.head')
        @if (config('broadcasting.default') === 'reverb')
            <script>
                window.omniEcho = @js([
                    'key' => config('broadcasting.connections.reverb.key'),
                    'userId' => auth()->id(),
                    'inboxUrl' => route('conversations.index'),
                    'newMessage' => __('New WhatsApp message'),
                    ...config('broadcasting.connections.reverb.browser'),
                ]);
            </script>
        @endif
        @if ($theme === \App\Enums\Theme::System)
            {{-- Follow the OS before first paint (no flash) and when it changes. --}}
            <script>
                (() => {
                    const query = window.matchMedia('(prefers-color-scheme: light)');
                    const apply = () => { document.documentElement.dataset.theme = query.matches ? 'light' : 'dark'; };
                    apply();
                    query.addEventListener('change', apply);
                })();
            </script>
        @endif
    </head>
    <body class="app relative flex min-h-svh flex-col noise">
        @php
            $nav = [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'current' => request()->routeIs('dashboard'), 'n' => '00'],
                ['label' => __('Projects'), 'href' => route('projects.index'), 'current' => request()->routeIs('projects.*') || request()->routeIs('icps.*'), 'n' => '01'],
                ['label' => __('Leads'), 'href' => route('leads.index'), 'current' => request()->routeIs('leads.*'), 'n' => '02'],
            ];
            // The inbox shows up once WhatsApp is connected, or while there are chats to read.
            $whatsappUnread = (int) \App\Models\WhatsAppConversation::query()->where('user_id', auth()->id())->sum('unread_count');
            if ($whatsappUnread > 0 || auth()->user()->hasIntegration(\App\Enums\IntegrationProvider::Evolution)) {
                $nav[] = ['label' => __('Conversations'), 'href' => route('conversations.index'), 'current' => request()->routeIs('conversations.*'), 'n' => '03', 'badge' => true];
            }
        @endphp

        <header class="fixed inset-x-0 top-0 z-40 border-b border-white/6 bg-ink/70 backdrop-blur-md">
            <div class="mx-auto flex h-14 max-w-7xl items-center gap-6 px-5">
                <x-brand :href="route('dashboard')" wire:navigate />

                <nav class="hidden items-center gap-1 md:flex">
                    @foreach ($nav as $item)
                        <a href="{{ $item['href'] }}" wire:navigate
                           class="group flex items-center gap-2 rounded-full px-3 py-1.5 text-sm transition {{ $item['current'] ? 'bg-white/6 text-white' : 'text-zinc-400 hover:text-white' }}">
                            <span class="font-mono text-2xs {{ $item['current'] ? 'text-signal' : 'text-zinc-600 group-hover:text-zinc-400' }}">{{ $item['n'] }}</span>
                            {{ $item['label'] }}
                            @isset($item['badge'])
                                <span data-whatsapp-unread @class(['font-mono rounded-full bg-signal px-1.5 text-2xs font-semibold text-zinc-950', 'hidden' => $whatsappUnread === 0])>{{ $whatsappUnread }}</span>
                            @endisset
                        </a>
                    @endforeach
                </nav>

                <div class="ml-auto flex items-center gap-2">
                    <a href="{{ route('home') }}" class="font-mono hidden text-2xs uppercase tracking-label text-zinc-500 hover:text-white lg:block">{{ __('Site') }}</a>

                    <x-ui.language-switcher />
                    <x-ui.theme-switcher />

                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="flex items-center gap-2 rounded-full border border-white/10 py-1 pl-1 pr-3 text-sm text-zinc-300 transition hover:border-white/30 hover:text-white" data-test="sidebar-menu-button">
                            <x-ui.avatar :user="auth()->user()" />
                            <span class="hidden max-w-32 truncate sm:block">{{ Str::before(auth()->user()->name, ' ') }}</span>
                            <flux:icon.chevron-down class="size-3.5 text-zinc-500" />
                        </button>

                        <flux:menu class="min-w-56">
                            <div class="px-2 py-2">
                                <div class="truncate text-sm text-white">{{ auth()->user()->name }}</div>
                                <div class="font-mono truncate text-2xs text-zinc-500">{{ auth()->user()->email }}</div>
                            </div>
                            <flux:menu.separator />
                            <div class="md:hidden">
                                @foreach ($nav as $item)
                                    <flux:menu.item :href="$item['href']" wire:navigate>
                                        {{ $item['label'] }}
                                        @isset($item['badge'])
                                            <span data-whatsapp-unread @class(['font-mono ml-2 rounded-full bg-signal px-1.5 text-2xs font-semibold text-zinc-950', 'hidden' => $whatsappUnread === 0])>{{ $whatsappUnread }}</span>
                                        @endisset
                                    </flux:menu.item>
                                @endforeach
                                <flux:menu.separator />
                            </div>
                            <flux:menu.item :href="route('profile.edit')" icon="cog-6-tooth" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                            <flux:menu.item :href="route('integrations.edit')" icon="key" wire:navigate>{{ __('Integrations') }}</flux:menu.item>
                            <flux:menu.item :href="route('api-tokens.index')" icon="code-bracket" wire:navigate>{{ __('API') }}</flux:menu.item>
                            @can('invite-users')
                                <flux:menu.item :href="route('invitations.index')" icon="user-plus" wire:navigate>{{ __('Invite users') }}</flux:menu.item>
                            @endcan
                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                @csrf
                                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
                                    {{ __('Log out') }}
                                </flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>
        </header>

        <main class="relative isolate mx-auto w-full max-w-7xl flex-1 px-5 pb-16 pt-24 md:pt-28">
            <div class="grid-bg pointer-events-none absolute inset-x-0 top-0 -z-10 h-[40vh]"></div>
            <x-whatsapp.paused />
            {{ $slot }}
        </main>

        <footer class="mx-auto flex w-full max-w-7xl flex-wrap items-center justify-between gap-3 border-t border-white/6 px-5 py-4">
            <a href="{{ route('home') }}">
                <img src="{{ asset('images/omni-leads-logo.png') }}" alt="Omni Leads" class="brand-logo h-12 w-auto">
            </a>
            <span class="font-mono text-2xs text-zinc-600">{{ __('ICP → discovery → enrichment → score') }}</span>
        </footer>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
