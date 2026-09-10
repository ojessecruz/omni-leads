<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <flux:select wire:model.live="locale" :label="__('Language')">
            @foreach (\App\Support\Locales::SUPPORTED as $code => $name)
                <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
        <p class="font-mono mt-6 text-2xs text-zinc-500">{{ __('Omni Leads is dark by design. There is no light theme.') }}</p>
    </x-settings.layout>
</section>
