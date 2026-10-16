<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <div class="mt-6 flex items-center gap-5">
            <div class="relative">
                <x-ui.avatar :user="auth()->user()" size="size-20" text="text-xl" />
                <div wire:loading.flex wire:target="photo" class="absolute inset-0 items-center justify-center rounded-full bg-ink/70">
                    <flux:icon.arrow-path class="size-5 animate-spin text-signal" />
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex flex-wrap gap-2">
                    <label class="cursor-pointer">
                        <input type="file" wire:model="photo" accept="image/png,image/jpeg,image/webp" class="sr-only" data-test="avatar-input">
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 px-3 py-1.5 text-sm text-zinc-200 transition hover:border-white/30 hover:text-white">
                            <flux:icon.camera class="size-4" />
                            {{ auth()->user()->avatar_path ? __('Change photo') : __('Upload photo') }}
                        </span>
                    </label>

                    @if (auth()->user()->avatar_path)
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removePhoto">{{ __('Remove') }}</flux:button>
                    @endif
                </div>
                <p class="font-mono text-2xs text-zinc-500">{{ __('JPG, PNG or WebP, up to 2 MB.') }}</p>
                <flux:error name="photo" />
            </div>
        </div>

        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link as="button" type="button" class="text-sm" wire:click="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
