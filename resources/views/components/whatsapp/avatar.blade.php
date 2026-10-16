@props(['conversation'])

{{-- Contact photo copied from WhatsApp (the version in the URL busts the browser cache when it changes); initials otherwise. --}}
@if ($conversation->avatar_path)
    <img src="{{ route('conversations.avatar', ['conversation' => $conversation, 'v' => $conversation->avatar_checked_at?->timestamp]) }}" alt="" loading="lazy"
         {{ $attributes->class('shrink-0 rounded-full border border-white/10 object-cover') }}>
@else
    <span {{ $attributes->class('font-mono flex shrink-0 items-center justify-center rounded-full border border-white/10 text-2xs text-zinc-400') }}>{{ Str::upper(Str::substr($conversation->displayName(), 0, 2)) }}</span>
@endif
