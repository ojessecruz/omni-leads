@php
    $steps = [
        [__('Create a project in Google Cloud, or pick an existing one.'), 'https://console.cloud.google.com/projectcreate', __('Create project')],
        [__('Link a billing account. Google requires it even inside the free monthly quota.'), 'https://console.cloud.google.com/billing', __('Billing')],
        [__('Enable the “Places API (New)” in the project.'), 'https://console.cloud.google.com/apis/library/places.googleapis.com', __('Enable API')],
        [__('Go to Credentials > Create credentials > API key and copy the key.'), 'https://console.cloud.google.com/apis/credentials', __('Credentials')],
        [__('Edit the key: under API restrictions, allow only “Places API (New)”. Leave application restrictions as “None”, since the calls come from our server.'), null, null],
        [__('Paste the key below and click Connect. We test it before saving.'), null, null],
    ];
@endphp

<details id="google-places-guide" class="group mt-4 scroll-mt-24 rounded-lg border border-white/8 bg-white/2" @if (! $connected) open @endif>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 text-sm font-medium text-white">
        <span>{{ __('How to get your key') }} <span class="font-mono text-2xs text-zinc-500">· ~5 min</span></span>
        <flux:icon.chevron-down variant="micro" class="text-zinc-500 transition group-open:rotate-180" />
    </summary>
    <ol class="space-y-3 px-4 pb-4">
        @foreach ($steps as [$text, $url, $label])
            <li class="flex gap-3 text-sm text-zinc-300">
                <span class="font-mono mt-0.5 shrink-0 text-2xs text-signal">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                <span>
                    {{ $text }}
                    @if ($url)
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="font-mono ml-1 whitespace-nowrap text-2xs uppercase tracking-label text-signal hover:underline">{{ $label }} ↗</a>
                    @endif
                </span>
            </li>
        @endforeach
    </ol>
    <p class="border-t border-white/6 px-4 py-3 text-xs text-zinc-500">{{ __('Usage above the free quota is billed by Google to your Cloud account. You can set a budget alert in Billing to be safe.') }}</p>
</details>
