<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="app relative min-h-svh noise">
        <div class="grid min-h-svh grid-cols-1 lg:grid-cols-12">
            <aside class="relative isolate hidden overflow-hidden border-r border-white/6 p-10 lg:col-span-6 lg:flex lg:flex-col xl:col-span-7">
                <div class="grid-bg absolute inset-0 -z-10"></div>
                <div class="glow absolute inset-x-0 top-0 -z-10 h-[60vh]"></div>

                <x-brand />

                <div class="mt-auto max-w-xl">
                    <p class="eyebrow mb-6 flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span>ICP</span><span class="text-zinc-700">→</span>
                        <span>{{ __('discovery') }}</span><span class="text-zinc-700">→</span>
                        <span>{{ __('enrichment') }}</span><span class="text-zinc-700">→</span>
                        <span class="text-signal">{{ __('score') }}</span>
                    </p>
                    <h1 class="display text-6xl font-semibold text-white xl:text-7xl">
                        {{ __('Your next 500 customers,') }}<br>
                        <span class="serif-it text-zinc-300">{{ __('already listed.') }}</span>
                    </h1>
                    <ul class="font-mono mt-10 space-y-2 text-xs text-zinc-400">
                        @foreach (['google_places' => __('text search, one query per keyword × region'), 'hunter' => __('email by domain, cached per lead'), 'evolution' => __('WhatsApp validation and first message'), 'score' => __('normalized over the criteria you actually set')] as $k => $v)
                            <li class="flex gap-3"><span class="w-28 shrink-0 text-zinc-600">{{ $k }}</span><span>{{ $v }}</span></li>
                        @endforeach
                    </ul>
                </div>
            </aside>

            <div class="flex flex-col px-6 py-8 lg:col-span-6 xl:col-span-5">
                <x-brand class="lg:hidden" />
                <div class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-10">
                    {{ $slot }}
                </div>
                <p class="font-mono text-2xs text-zinc-600">{{ __('No credit card. Bring your own API keys.') }}</p>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
