@props(['user', 'size' => 'size-7', 'text' => 'text-2xs'])

@if ($url = $user->avatarUrl())
    <img src="{{ $url }}" alt="{{ $user->name }}" {{ $attributes->class([$size, 'shrink-0 rounded-full object-cover']) }}>
@else
    <span {{ $attributes->class([$size, $text, 'font-mono flex shrink-0 items-center justify-center rounded-full bg-signal font-semibold text-ink']) }}>{{ $user->initials() }}</span>
@endif
