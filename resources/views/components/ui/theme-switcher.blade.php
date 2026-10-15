@php
    use App\Enums\Theme;

    // With "system" the shown theme is only known in the browser, so the click handler picks the opposite of
    // what <html> currently shows; this value is the no-JS fallback.
    $next = auth()->user()->theme === Theme::Light ? Theme::Dark : Theme::Light;
@endphp

{{-- One click toggles light/dark. The icon shows where it goes: sun on the dark theme, moon on the light one. --}}
<form method="POST" action="{{ route('theme.update') }}" {{ $attributes }}>
    @csrf
    <button type="submit" name="theme" value="{{ $next->value }}"
            onclick="this.value = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light'"
            class="flex cursor-pointer items-center rounded-full border border-white/10 p-2 text-zinc-400 transition hover:border-white/30 hover:text-white"
            aria-label="{{ __('Toggle theme') }}" title="{{ __('Toggle theme') }}">
        <flux:icon.sun variant="micro" class="size-3.5 [html[data-theme=light]_&]:hidden" />
        <flux:icon.moon variant="micro" class="hidden size-3.5 [html[data-theme=light]_&]:block" />
    </button>
</form>
