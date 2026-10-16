<div>
    @if ($visible)
        <x-ui.panel class="mb-6" :eyebrow="__('First steps').' · '.$completed.' / '.count($steps)" :title="__('Get your first qualified leads')" :padding="false" data-reveal>
            <x-slot name="actions">
                <flux:button size="sm" variant="ghost" wire:click="dismiss" :wire:confirm="__('Hide the first steps? You can still do them from the menus.')">{{ __('Dismiss') }}</flux:button>
            </x-slot>

            <ol class="divide-y divide-white/6">
                @foreach ($steps as $step)
                    @php($isNext = $next?->key === $step->key)
                    <li wire:key="onboarding-{{ $step->key }}" @class(['flex flex-wrap items-center gap-4 px-5 py-4', 'bg-signal/4' => $isNext])>
                        <span @class([
                            'font-mono flex size-8 shrink-0 items-center justify-center rounded-full border text-2xs',
                            'border-signal bg-signal text-zinc-950' => $step->done,
                            'border-signal/60 text-signal' => $isNext,
                            'border-white/10 text-zinc-500' => ! $step->done && ! $isNext,
                        ])>
                            @if ($step->done)
                                <flux:icon.check variant="micro" />
                            @else
                                {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}
                            @endif
                        </span>
                        <div class="min-w-0 flex-1 basis-60">
                            <p @class(['text-sm font-medium', 'text-zinc-500 line-through' => $step->done, 'text-white' => ! $step->done])>
                                {{ $step->title }}
                                @unless ($step->required)
                                    <span class="chip ml-1 no-underline">{{ __('Optional') }}</span>
                                @endunless
                            </p>
                            @unless ($step->done)
                                <p class="mt-0.5 text-sm text-zinc-400">{{ $step->description }}</p>
                            @endunless
                        </div>
                        @unless ($step->done)
                            <flux:button size="sm" :href="$step->url" wire:navigate :variant="$isNext ? 'primary' : 'ghost'">{{ $step->action }} →</flux:button>
                        @endunless
                    </li>
                @endforeach
            </ol>
        </x-ui.panel>
    @endif
</div>
