<div class="flex flex-col gap-6">
    @if ($invitation)
        <x-auth-header eyebrow="00 / {{ __('Invitation') }}" :title="__('Create your account')" :description="__(':name invited you to Omni Leads. Choose your name and password.', ['name' => $invitation->inviter->name ?? 'Omni Leads'])" />

        <form wire:submit="accept" class="flex flex-col gap-6">
            <flux:input :label="__('Email address')" :value="$invitation->email" type="email" readonly disabled />

            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" :placeholder="__('Full name')" />

            <flux:input wire:model="password" :label="__('Password')" type="password" required autocomplete="new-password" :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}" viewable />

            <flux:input wire:model="password_confirmation" :label="__('Confirm password')" type="password" required autocomplete="new-password" :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}" viewable />

            <flux:button type="submit" variant="primary" class="w-full" data-test="accept-invitation-button">{{ __('Create account') }}</flux:button>
        </form>
    @else
        <x-auth-header eyebrow="00 / {{ __('Invitation') }}" :title="__('This invitation is no longer valid')" :description="__('The link was already used, expired or was revoked. Ask for a new invitation.')" />
    @endif

    <div class="text-sm text-zinc-500">
        <span>{{ __('Already have an account?') }}</span>
        <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
    </div>
</div>
