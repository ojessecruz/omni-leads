<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Invitations') }}</flux:heading>

    <x-settings.layout :heading="__('Invitations')" :subheading="__('Sign-up is closed: new users join only by invitation. The link goes by email, works once and expires in :days days.', ['days' => \App\Models\Invitation::VALID_DAYS])">
        <form wire:submit="invite" class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="flex-1">
                <flux:input wire:model="email" type="email" :placeholder="__('name@company.com')" :aria-label="__('Email')" required />
            </div>
            <flux:button type="submit" variant="primary" icon="paper-airplane" data-test="invite-button">{{ __('Send invitation') }}</flux:button>
        </form>

        <div class="mt-10">
            <p class="eyebrow">{{ __('Sent') }}</p>

            @forelse ($invitations as $invitation)
                <div wire:key="invitation-{{ $invitation->id }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-white/6 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm text-white">{{ $invitation->email }}</p>
                        <p class="font-mono mt-0.5 text-2xs text-zinc-500">
                            @if ($invitation->accepted_at)
                                {{ __('Accepted :when', ['when' => $invitation->accepted_at->diffForHumans()]) }}
                            @elseif ($invitation->isExpired())
                                {{ __('Expired :when', ['when' => $invitation->expires_at->diffForHumans()]) }}
                            @else
                                {{ __('Sent :when, expires :expires', ['when' => $invitation->updated_at?->diffForHumans(), 'expires' => $invitation->expires_at->diffForHumans()]) }}
                            @endif
                            @if ($invitation->inviter) · {{ $invitation->inviter->name }} @endif
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        @if ($invitation->accepted_at)
                            <span class="chip chip-signal">{{ __('Accepted') }}</span>
                        @else
                            <span class="chip">{{ $invitation->isExpired() ? __('Expired') : __('Pending') }}</span>
                            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="resend({{ $invitation->id }})">{{ __('Resend') }}</flux:button>
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="revoke({{ $invitation->id }})" wire:confirm="{{ __('Revoke the invitation for :email?', ['email' => $invitation->email]) }}">{{ __('Revoke') }}</flux:button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="mt-4 text-sm text-zinc-500">{{ __('No invitations yet.') }}</p>
            @endforelse
        </div>
    </x-settings.layout>
</section>
