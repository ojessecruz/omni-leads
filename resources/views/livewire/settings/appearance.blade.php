<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <flux:select wire:model.live="locale" :label="__('Language')">
            @foreach (\App\Support\Locales::SUPPORTED as $code => $name)
                <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>

        {{-- Same pill pattern as the top nav: mono index, subtle active fill, signal-colored index on the active option. --}}
        <flux:field class="mt-6">
            <flux:label>{{ __('Theme') }}</flux:label>
            <div role="radiogroup" aria-label="{{ __('Theme') }}" class="flex w-fit flex-wrap gap-1 rounded-full border border-white/10 bg-ink p-1">
                @foreach (\App\Enums\Theme::cases() as $i => $option)
                    <label wire:key="theme-{{ $option->value }}"
                           class="group flex cursor-pointer items-center gap-2 rounded-full px-3.5 py-1.5 text-sm text-zinc-400 transition hover:text-white has-checked:bg-white/6 has-checked:text-white has-focus-visible:outline-2 has-focus-visible:outline-signal">
                        <input type="radio" wire:model.live="theme" value="{{ $option->value }}" class="sr-only">
                        <span class="font-mono text-2xs text-zinc-600 group-hover:text-zinc-400 group-has-checked:text-signal">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <flux:icon :icon="$option->icon()" variant="micro" class="size-3.5" />
                        {{ $option->label() }}
                    </label>
                @endforeach
            </div>
            <flux:error name="theme" />
        </flux:field>
    </x-settings.layout>
</section>
