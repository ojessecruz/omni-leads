<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — {{ __('Lead discovery by ICP') }}</title>
    <meta name="description" content="{{ __('Describe who you sell to. Omni Leads turns it into searches across Google Places, Apollo and public registries, enriches every business with email and WhatsApp, and scores them against your ICP.') }}">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="relative noise overflow-x-hidden">

{{-- NAV --}}
<header class="fixed inset-x-0 top-0 z-50 border-b border-white/6 bg-ink/70 backdrop-blur-md">
    <div class="mx-auto flex h-14 max-w-7xl items-center justify-between px-5">
        <x-brand />
        @php($sections = ['#how' => __('How it works'), '#askai' => __('Ask AI'), '#score' => __('Scoring'), '#outbound' => __('Outbound')])
        <nav class="hidden items-center gap-8 text-sm text-zinc-400 md:flex">
            @foreach ($sections as $href => $label)
                <a href="{{ $href }}" class="hover:text-white">{{ $label }}</a>
            @endforeach
        </nav>
        <div class="flex items-center gap-2">
            {{-- Mobile: native disclosure, no JS needed (Alpine is not loaded on the landing). --}}
            <details class="relative md:hidden">
                <summary class="flex cursor-pointer list-none items-center rounded-full p-2 text-zinc-300 hover:text-white [&::-webkit-details-marker]:hidden" aria-label="{{ __('Menu') }}">
                    <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14" stroke-linecap="round"/></svg>
                </summary>
                <nav class="absolute right-0 mt-2 flex w-48 flex-col rounded-xl border border-white/10 bg-panel p-1.5 text-sm text-zinc-300 shadow-glow">
                    @foreach ($sections as $href => $label)
                        <a href="{{ $href }}" class="rounded-lg px-3 py-2 hover:bg-white/4 hover:text-white">{{ $label }}</a>
                    @endforeach
                </nav>
            </details>
            @auth
                <a href="{{ route('dashboard') }}" class="btn-signal rounded-full px-4 py-1.5 text-sm font-medium">{{ __('Open app') }}</a>
            @else
                <a href="{{ route('login') }}" class="rounded-full px-4 py-1.5 text-sm text-zinc-300 hover:text-white">{{ __('Log in') }}</a>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="btn-signal rounded-full px-4 py-1.5 text-sm font-medium">{{ __('Start free') }}</a>
                @endif
            @endauth
        </div>
    </div>
</header>

{{-- HERO --}}
<section class="relative isolate overflow-hidden pt-32 pb-20 md:pt-40 md:pb-28">
    <div class="grid-bg absolute inset-0 -z-10"></div>
    <div class="glow absolute inset-x-0 top-0 -z-10 h-[60vh]"></div>

    <div class="mx-auto grid grid-cols-1 max-w-7xl items-end gap-14 px-5 lg:grid-cols-12">
        <div class="lg:col-span-7" data-reveal>
            <p class="font-mono mb-8 flex flex-wrap items-center gap-x-3 gap-y-1 text-2xs uppercase tracking-eyebrow text-zinc-500">
                <span>ICP</span><span class="text-zinc-700">→</span>
                <span>{{ __('discovery') }}</span><span class="text-zinc-700">→</span>
                <span>{{ __('enrichment') }}</span><span class="text-zinc-700">→</span>
                <span class="text-signal">{{ __('score') }}</span>
            </p>
            <h1 class="display text-[13vw] font-semibold text-white sm:text-7xl md:text-8xl lg:text-[7.2rem]">
                {{ __('Your next 500 customers,') }}<br>
                <span class="serif-it text-zinc-300">{{ __('already listed.') }}</span>
            </h1>
            <p class="mt-8 max-w-xl text-lg leading-relaxed text-zinc-400 md:text-xl">
                {{ __('Describe who you sell to. Omni Leads turns it into searches across Google Places, Apollo and public registries, enriches every business with email and WhatsApp, and scores them against your ICP.') }}
            </p>
            <div class="mt-10 flex flex-wrap items-center gap-3">
                <a href="{{ Route::has('register') ? route('register') : route('login') }}" class="btn-signal rounded-full px-6 py-3 text-sm font-medium">{{ __('Start free') }}</a>
                <a href="#how" class="rounded-full border border-white/15 px-6 py-3 text-sm text-zinc-200 hover:border-white/40">{{ __('See how it works') }}</a>
                <span class="font-mono ml-2 text-xs text-zinc-500">{{ __('No credit card. Bring your own API keys.') }}</span>
            </div>
        </div>

        {{-- LIVE LOG --}}
        <div class="lg:col-span-5" data-reveal>
            <div class="overflow-hidden rounded-2xl border border-white/10 bg-panel shadow-glow">
                <div class="flex items-center justify-between border-b border-white/6 px-4 py-2.5">
                    <span class="font-mono text-2xs text-zinc-500">discovery.log — google_places · cnpj_br</span>
                    <span class="font-mono flex items-center gap-1.5 text-2xs text-signal"><span class="inline-block size-1.5 rounded-full bg-signal"></span>{{ __('live') }}</span>
                </div>
                <div id="log" class="font-mono min-h-[300px] overflow-hidden space-y-1.5 px-4 py-4 text-xs leading-5"></div>
                <div class="border-t border-white/6 px-4 py-2.5">
                    <span id="log-status" class="font-mono caret text-2xs text-zinc-500">{{ __('waiting for results') }}</span>
                </div>
            </div>
            <p class="font-mono mt-3 text-right text-2xs text-zinc-600">{{ __('sample data — real leads look exactly like this') }}</p>
        </div>
    </div>
</section>

{{-- MARQUEE --}}
<div class="overflow-hidden border-y border-white/6 py-4">
    <div class="marquee font-mono text-xs uppercase tracking-eyebrow text-zinc-500">
        @for ($i = 0; $i < 2; $i++)
            @foreach (['Google Places', 'Apollo', 'Receita Federal', 'Hunter.io', 'Evolution API', 'n8n', 'Make', 'CSV', 'JSON Schema', 'Horizon'] as $item)
                <span class="px-8">{{ $item }}</span><span class="text-signal">·</span>
            @endforeach
        @endfor
    </div>
</div>

{{-- HOW IT WORKS --}}
<section id="how" class="mx-auto max-w-7xl px-5 py-28 md:py-36">
    <div class="mb-16 flex items-end justify-between gap-6" data-reveal>
        <div>
            <p class="font-mono text-2xs uppercase tracking-eyebrow text-zinc-500">01 / {{ __('How it works') }}</p>
            <h2 class="display mt-4 text-5xl font-semibold text-white md:text-7xl">{{ __('ICP is data,') }} <span class="serif-it text-zinc-400">{{ __('not code.') }}</span></h2>
        </div>
        <p class="hidden max-w-sm text-zinc-400 md:block">{{ __('Every source plugs into the same pipeline. Add a driver, not a rewrite.') }}</p>
    </div>

    <div class="tick mb-10 w-full opacity-60"></div>

    <ol class="grid grid-cols-1 gap-px overflow-hidden rounded-2xl border border-white/10 bg-white/10 md:grid-cols-4">
        @foreach ([
            ['n' => '01', 't' => __('Define the ICP'), 'd' => __('Segment, keywords, regions, job titles and signals. Manually, or paste the JSON your AI agent returns.'), 'm' => 'icps.criteria → jsonb'],
            ['n' => '02', 't' => __('Discover'), 'd' => __('Each driver maps the ICP to its own query grid. Places by city, Apollo by title, registries by activity code.'), 'm' => 'queue: discovery'],
            ['n' => '03', 't' => __('Enrich'), 'd' => __('Free first: the website. Then Hunter for email, Evolution to validate WhatsApp. Every raw payload is kept.'), 'm' => 'queue: enrichment'],
            ['n' => '04', 't' => __('Score & act'), 'd' => __('Weighted score 0–100 against your criteria. Qualified leads hit your webhook or your WhatsApp.'), 'm' => 'queue: scoring'],
        ] as $step)
            <li class="group relative bg-panel p-7 transition hover:bg-zinc-800" data-reveal>
                <span class="font-mono text-2xs text-zinc-600">{{ $step['n'] }}</span>
                <h3 class="mt-6 text-2xl font-medium tracking-tight text-white">{{ $step['t'] }}</h3>
                <p class="mt-3 text-sm leading-relaxed text-zinc-400">{{ $step['d'] }}</p>
                <p class="font-mono mt-8 text-2xs text-zinc-600 group-hover:text-signal">{{ $step['m'] }}</p>
            </li>
        @endforeach
    </ol>
</section>

{{-- ASK AI --}}
<section id="askai" class="relative border-y border-white/6 bg-panel/50 py-28 md:py-36">
    <div class="mx-auto grid grid-cols-1 max-w-7xl gap-14 px-5 lg:grid-cols-12 lg:items-center">
        <div class="lg:col-span-5" data-reveal>
            <p class="font-mono text-2xs uppercase tracking-eyebrow text-zinc-500">02 / {{ __('Ask AI') }}</p>
            <h2 class="display mt-4 text-5xl font-semibold text-white md:text-6xl">{{ __('Zero tokens') }} <span class="serif-it text-zinc-400">{{ __('billed.') }}</span></h2>
            <p class="mt-6 text-lg text-zinc-400">{{ __('We do not call an LLM. We write the prompt, you paste it into the agent you already pay for, and bring the JSON back. Fences, stray commas, single quotes: the parser cleans it and validates against a schema.') }}</p>
            <ul class="font-mono mt-8 space-y-2 text-sm text-zinc-300">
                <li><span class="text-signal">✓</span> {{ __('Works with ChatGPT, Claude, Gemini, whatever') }}</li>
                <li><span class="text-signal">✓</span> {{ __('JSON Schema validation with human errors') }}</li>
                <li><span class="text-signal">✓</span> {{ __('Switch to manual at any step') }}</li>
            </ul>
        </div>
        <div class="grid grid-cols-1 gap-4 lg:col-span-7 md:grid-cols-2" data-reveal>
            <div class="rounded-2xl border border-white/10 bg-panel p-5">
                <p class="font-mono mb-3 text-2xs text-zinc-500">prompt.txt</p>
                <pre class="font-mono whitespace-pre-wrap text-xs leading-5 text-zinc-300">{{ __('You are an expert in defining ICPs.') }}

{{ __('Product context:') }}
- {{ __('Product') }}: {{ __('Online scheduling with WhatsApp reminders…') }}
- {{ __('Problem it solves') }}: {{ __('No-shows that hurt revenue…') }}

{{ __('Answer ONLY with a valid JSON:') }}
{ "industry", "keywords", "countries",
  "regions", "company_size", "job_titles",
  "signals": { … } }</pre>
            </div>
            <div class="rounded-2xl border border-signal/30 bg-panel p-5">
                <p class="font-mono mb-3 flex items-center justify-between text-2xs text-zinc-500"><span>answer.json</span><span class="text-signal">{{ __('valid') }} ✓</span></p>
<pre class="font-mono whitespace-pre-wrap text-xs leading-5 text-zinc-300">{
  <span class="text-zinc-500">"industry"</span>: "aesthetics",
  <span class="text-zinc-500">"keywords"</span>: ["aesthetic clinic", "day spa"],
  <span class="text-zinc-500">"countries"</span>: ["BR", "PT"],
  <span class="text-zinc-500">"regions"</span>: ["São Paulo", "Lisboa"],
  <span class="text-zinc-500">"company_size"</span>: "1-10",
  <span class="text-zinc-500">"job_titles"</span>: ["owner", "founder"],
  <span class="text-zinc-500">"signals"</span>: {
    "has_website": true,
    "min_reviews": 20,
    "min_rating": 4.3
  }
}</pre>
            </div>
        </div>
    </div>
</section>

{{-- SCORE --}}
<section id="score" class="mx-auto max-w-7xl px-5 py-28 md:py-36">
    <div class="grid grid-cols-1 gap-14 lg:grid-cols-12 lg:items-center">
        <div class="order-2 lg:order-1 lg:col-span-6" data-reveal>
            <div class="rounded-2xl border border-white/10 bg-panel p-6 md:p-8">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-lg font-medium text-white">Clínica Bella Estética</p>
                        <p class="font-mono text-2xs text-zinc-500">bellaestetica.com.br · São Paulo, BR · google_places + cnpj_br</p>
                    </div>
                    <div class="text-right">
                        <span class="display font-mono text-5xl font-medium text-signal" id="score-num">0</span><span class="font-mono text-sm text-zinc-500">/100</span>
                    </div>
                </div>
                <ul class="mt-6 divide-y divide-white/6 text-sm">
                    @foreach ([
                        [__('Country within ICP'), 'BR', 20, true],
                        [__('Has its own website'), 'bellaestetica.com.br', 20, true],
                        [__('≥ :n reviews', ['n' => 20]), __(':n reviews', ['n' => 120]), 15, true],
                        [__('Rating ≥ :n', ['n' => '4.3']), __('rating :n', ['n' => '4.8']), 15, true],
                        [__('Email found'), 'contato@bellaestetica.com.br', 15, true],
                        [__('Valid WhatsApp'), __('not verified'), 15, false],
                    ] as [$label, $detail, $w, $met])
                        <li class="flex items-center justify-between gap-4 py-2.5">
                            <span class="flex min-w-0 items-center gap-2 text-zinc-300">
                                <span class="font-mono text-xs {{ $met ? 'text-signal' : 'text-zinc-600' }}">{{ $met ? '✓' : '—' }}</span>
                                <span class="min-w-0"><span class="block sm:inline">{{ $label }}</span> <span class="font-mono block truncate text-2xs text-zinc-500 sm:inline">{{ $detail }}</span></span>
                            </span>
                            <span class="font-mono text-xs {{ $met ? 'text-zinc-200' : 'text-zinc-600 line-through' }}">+{{ $w }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-5 h-1 w-full overflow-hidden rounded-full bg-white/6"><div class="bar h-full w-[85%] rounded-full bg-signal"></div></div>
            </div>
        </div>
        <div class="order-1 lg:order-2 lg:col-span-6" data-reveal>
            <p class="font-mono text-2xs uppercase tracking-eyebrow text-zinc-500">03 / {{ __('Scoring') }}</p>
            <h2 class="display mt-4 text-5xl font-semibold text-white md:text-6xl">{{ __('Every lead gets') }} <span class="serif-it text-zinc-400">{{ __('a number.') }}</span></h2>
            <p class="mt-6 text-lg text-zinc-400">{{ __('Six weighted signals, normalized over what your ICP actually asks for. Above the threshold the lead is qualified and your webhook fires. Weights live in config, not in a deploy.') }}</p>
            <div class="font-mono mt-8 grid grid-cols-3 gap-4 text-center text-xs text-zinc-500">
                <div class="rounded-xl border border-white/10 p-3 md:p-4"><p class="display text-2xl text-white md:text-3xl">6</p>{{ __('signals') }}</div>
                <div class="rounded-xl border border-white/10 p-3 md:p-4"><p class="display whitespace-nowrap text-2xl text-white md:text-3xl">0–100</p>{{ __('normalized') }}</div>
                <div class="rounded-xl border border-white/10 p-3 md:p-4"><p class="display text-2xl text-white md:text-3xl">100%</p>{{ __('raw payload kept') }}</div>
            </div>
        </div>
    </div>
</section>

{{-- OUTBOUND / BENTO --}}
<section id="outbound" class="border-t border-white/6 bg-panel/50 py-28 md:py-36">
    <div class="mx-auto max-w-7xl px-5">
        <div class="mb-14" data-reveal>
            <p class="font-mono text-2xs uppercase tracking-eyebrow text-zinc-500">04 / {{ __('Outbound') }}</p>
            <h2 class="display mt-4 text-5xl font-semibold text-white md:text-7xl">{{ __('Built to leave') }} <span class="serif-it text-zinc-400">{{ __('the spreadsheet.') }}</span></h2>
        </div>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
            <div class="rounded-2xl border border-white/10 bg-panel p-7 md:col-span-4" data-reveal>
                <p class="font-mono text-2xs text-zinc-500">POST · X-OmniLeads-Signature: sha256=…</p>
                <h3 class="mt-4 text-2xl font-medium text-white">{{ __('Signed webhooks') }}</h3>
                <p class="mt-2 max-w-md text-zinc-400">{{ __('lead.qualified, lead.contacted, lead.enriched, search.finished. HMAC-signed, five retries with backoff, auto-pause after repeated failures. n8n and Make just work.') }}</p>
                <pre class="font-mono mt-6 overflow-x-auto rounded-xl border border-white/6 bg-black/40 p-4 text-xs leading-5 text-zinc-400">{
  "event": "lead.qualified",
  "data": { "id": 4812, "company_name": "Clínica Bella Estética",
            "score": 85, "email": "contato@…", "whatsapp_valid": true }
}</pre>
            </div>
            <div class="rounded-2xl border border-white/10 bg-panel p-7 md:col-span-2" data-reveal>
                <p class="font-mono text-2xs text-zinc-500">evolution · sendText</p>
                <h3 class="mt-4 text-2xl font-medium text-white">{{ __('WhatsApp, first touch') }}</h3>
                <p class="mt-2 text-zinc-400">{{ __('Validated number, templated message, one click. The lead is marked as contacted on delivery.') }}</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-panel p-7 md:col-span-2" data-reveal>
                <p class="font-mono text-2xs text-zinc-500">schedule · hourly</p>
                <h3 class="mt-4 text-2xl font-medium text-white">{{ __('Re-runs itself') }}</h3>
                <p class="mt-2 text-zinc-400">{{ __('Every 7, 14 or 30 days the ICP searches again. New businesses show up, duplicates merge by tax ID, domain, phone or email.') }}</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-panel p-7 md:col-span-2" data-reveal>
                <p class="font-mono text-2xs text-zinc-500">export · csv ; utf-8</p>
                <h3 class="mt-4 text-2xl font-medium text-white">{{ __('Or just export') }}</h3>
                <p class="mt-2 text-zinc-400">{{ __('Filtered, selected, one click. Opens clean in Excel and Numbers.') }}</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-panel p-7 md:col-span-2" data-reveal>
                <p class="font-mono text-2xs text-zinc-500">config · cost_per_request</p>
                <h3 class="mt-4 text-2xl font-medium text-white">{{ __('Know what it costs') }}</h3>
                <p class="mt-2 text-zinc-400">{{ __('Every API request is counted per ICP. Estimated spend sits next to the funnel.') }}</p>
            </div>
        </div>
    </div>
</section>

{{-- CTA --}}
<section class="relative isolate overflow-hidden py-32 md:py-44">
    <div class="glow absolute inset-x-0 bottom-0 -z-10 h-[60vh] rotate-180"></div>
    <div class="mx-auto max-w-7xl px-5 text-center" data-reveal>
        <h2 class="display text-[14vw] font-semibold text-white sm:text-7xl md:text-8xl lg:text-[8rem]">{{ __('Stop scraping.') }}<br><span class="serif-it text-zinc-400">{{ __('Start selecting.') }}</span></h2>
        <div class="mt-12 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ Route::has('register') ? route('register') : route('login') }}" class="btn-signal rounded-full px-8 py-3.5 text-sm font-medium">{{ __('Start free') }}</a>
            <a href="{{ route('login') }}" class="rounded-full border border-white/15 px-8 py-3.5 text-sm text-zinc-200 hover:border-white/40">{{ __('Log in') }}</a>
        </div>
    </div>
</section>

<footer class="border-t border-white/6 py-5">
    <div class="font-mono mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 text-2xs text-zinc-600">
        <a href="{{ route('home') }}" class="flex items-center gap-3">
            <img src="{{ asset('images/omni-leads-logo.png') }}" alt="Omni Leads" class="h-14 w-auto">
            <span>{{ date('Y') }}</span>
        </a>
        <span>{{ __('Bring your own API keys. Your data stays in your database.') }}</span>
    </div>
</footer>

<script>
(() => {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Reveal on scroll
    const io = new IntersectionObserver((entries) => {
        entries.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } });
    }, { threshold: 0.12 });
    document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));

    // Live discovery log
    const rows = [
        ['google_places', 'Clínica Bella Estética', 'São Paulo, BR', 85],
        ['google_places', 'Day Spa Moema', 'São Paulo, BR', 70],
        ['cnpj_br', 'Harmoniza Face Ltda', 'Campinas, BR', 55],
        ['google_places', 'Estética Lisboa Premium', 'Lisboa, PT', 70],
        ['apollo', 'Ana Silva · Owner @ Spa Ana', 'Miami, US', 60],
        ['cnpj_br', 'Corte & Cia ME', 'Guarulhos, BR', 35],
        ['google_places', 'Studio Pele', 'Santos, BR', 45],
        ['hunter', '↳ contato@bellaestetica.com.br', '+15 email', null],
        ['evolution', '↳ +55 11 99999-0000 · WhatsApp ✓', '+15 whatsapp', null],
        ['google_places', 'Espaço Renova', 'Curitiba, BR', 80],
    ];
    const log = document.getElementById('log');
    const status = document.getElementById('log-status');
    const labels = { found: @js(__('found')), enriched: @js(__('enriched')), qualified: @js(__('qualified')) };
    let i = 0, count = 0, qualified = 0;

    const render = ([src, name, meta, score]) => {
        const row = document.createElement('div');
        row.className = 'log-row flex items-center gap-3';
        const isEnrich = score === null;
        const q = !isEnrich && score >= 60;
        if (q) qualified++;
        if (!isEnrich) count++;
        row.innerHTML = `
            <span class="w-[92px] shrink-0 truncate text-zinc-600">${src}</span>
            <span class="min-w-0 flex-1 truncate ${isEnrich ? 'text-zinc-500' : 'text-zinc-200'}">${name}<span class="ml-2 text-zinc-600">${meta}</span></span>
            ${isEnrich ? '' : `<span class="w-16 shrink-0"><span class="block h-1 w-full rounded-full bg-white/6"><span class="bar block h-1 rounded-full ${q ? 'bg-signal' : 'bg-zinc-600'}" style="width:${score}%"></span></span></span>
            <span class="w-6 shrink-0 text-right ${q ? 'text-signal' : 'text-zinc-500'}">${score}</span>`}`;
        log.appendChild(row);
        while (log.children.length > 9) log.removeChild(log.firstChild);
        status.textContent = `${count} ${labels.found} · ${qualified} ${labels.qualified}`;
    };

    if (reduced) { rows.slice(0, 8).forEach(render); return; }

    const tick = () => {
        render(rows[i % rows.length]);
        i++;
        setTimeout(tick, 900 + Math.random() * 1400);
    };
    setTimeout(tick, 600);

    // Score counter
    const num = document.getElementById('score-num');
    const scoreIo = new IntersectionObserver((entries) => {
        if (!entries[0].isIntersecting) return;
        scoreIo.disconnect();
        const start = performance.now();
        const step = (t) => {
            const p = Math.min(1, (t - start) / 1200);
            num.textContent = Math.round(85 * (1 - Math.pow(1 - p, 3)));
            if (p < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    });
    scoreIo.observe(num);
})();
</script>
</body>
</html>
