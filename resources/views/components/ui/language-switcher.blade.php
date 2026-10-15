@php
    use App\Support\Locales;

    $current = Locales::isSupported(app()->getLocale()) ? app()->getLocale() : Locales::DEFAULT;
@endphp

{{-- Native disclosure plus a plain form: works on the landing page, where neither Alpine nor Flux is loaded. --}}
<details {{ $attributes->class('relative') }}>
    <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-full border border-white/10 px-2.5 py-1.5 font-mono text-2xs uppercase tracking-label text-zinc-400 transition hover:border-white/30 hover:text-white [&::-webkit-details-marker]:hidden"
             aria-label="{{ __('Language') }}">
        <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <circle cx="10" cy="10" r="7.25" />
            <path d="M2.75 10h14.5M10 2.75c1.9 2 2.9 4.4 2.9 7.25s-1 5.25-2.9 7.25c-1.9-2-2.9-4.4-2.9-7.25S8.1 4.75 10 2.75Z" />
        </svg>
        {{ Locales::SHORT[$current] }}
    </summary>

    <form method="POST" action="{{ route('locale.update') }}"
          class="absolute right-0 z-50 mt-2 w-52 rounded-xl border border-white/10 bg-panel p-1.5 shadow-glow">
        @csrf
        @foreach (Locales::SUPPORTED as $code => $name)
            <button type="submit" name="locale" value="{{ $code }}" wire:key="locale-{{ $code }}"
                    @disabled($code === $current)
                    class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm {{ $code === $current ? 'text-signal' : 'cursor-pointer text-zinc-300 hover:bg-white/4 hover:text-white' }}">
                <span>{{ $name }}</span>
                <span class="font-mono text-2xs text-zinc-600">{{ Locales::SHORT[$code] }}</span>
            </button>
        @endforeach
    </form>
</details>
