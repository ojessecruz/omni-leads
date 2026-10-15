<div class="mx-auto w-full max-w-4xl">
    <x-ui.page-header :title="__('Define ICP')" :accent="__('step :n of :total.', ['n' => $step, 'total' => count($steps)])" class="mb-8">
        <x-slot name="crumbs">
            <x-ui.crumb :href="route('projects.index')">{{ __('Projects') }}</x-ui.crumb>
            <x-ui.crumb :href="route('projects.show', $project)">{{ $project->name }}</x-ui.crumb>
            <x-ui.crumb>{{ __('New ICP') }}</x-ui.crumb>
        </x-slot>
    </x-ui.page-header>

    {{-- Step rail --}}
    <ol class="font-mono mb-8 flex flex-wrap gap-x-6 gap-y-2 text-2xs uppercase tracking-label" data-reveal>
        @foreach ($steps as $n => $label)
            <li wire:key="step-{{ $n }}">
                <button type="button"
                        wire:click="goTo({{ $n }})"
                        @disabled($n >= $step)
                        class="flex items-center gap-2 transition
                            {{ $n === $step ? 'text-white' : '' }}
                            {{ $n < $step ? 'text-zinc-400 hover:text-white' : '' }}
                            {{ $n > $step ? 'text-zinc-600' : '' }}">
                    <span class="{{ $n === $step ? 'text-signal' : ($n < $step ? 'text-zinc-500' : 'text-zinc-700') }}">{{ str_pad((string) $n, 2, '0', STR_PAD_LEFT) }}</span>
                    {{ __($label) }}
                </button>
            </li>
        @endforeach
    </ol>
    <div class="tick mb-8 w-full opacity-40"></div>

    <form wire:submit="next" class="panel space-y-6 p-6 md:p-8" data-reveal>
        @if ($step === 1)
            <div>
                <h2 class="display text-3xl font-semibold text-white md:text-4xl">{{ __('Project context') }}</h2>
                <p class="mt-3 text-sm text-zinc-400">{{ __('Saved on the project and reused in future ICPs.') }}</p>
            </div>
            <flux:textarea wire:model="draft.product_description" :label="__('What is the product?')" rows="3" :placeholder="__('e.g. Online scheduling with WhatsApp reminders for small clinics.')" />
            <flux:textarea wire:model="draft.problem" :label="__('What problem does it solve?')" rows="3" :placeholder="__('e.g. No-shows and last-minute rescheduling that hurt revenue.')" />
            <flux:textarea wire:model="draft.current_customers" :label="__('Who already buys today?')" rows="2" :placeholder="__('e.g. 12 aesthetic clinics in São Paulo, 1 to 5 employees.')" />
            <flux:input wire:model="draft.avg_ticket" :label="__('Average ticket')" :placeholder="__('e.g. $99/month')" />

        @elseif ($step === 2)
            <div>
                <h2 class="display text-3xl font-semibold text-white md:text-4xl">{{ __('How do you want to define the ICP?') }}</h2>
                <p class="mt-3 text-sm text-zinc-400">{{ __('Manual walks you through the criteria. Ask AI generates a prompt to paste into your agent.') }}</p>
            </div>
            <flux:radio.group wire:model="mode" variant="cards" class="max-sm:flex-col">
                <flux:radio value="manual" icon="pencil-square" :label="__('Manual')" :description="__('I fill in segment, regions, job titles and signals step by step.')" />
                <flux:radio value="ask_ai" icon="sparkles" :label="__('Ask AI')" :description="__('I copy a ready-made prompt, paste it into my agent (ChatGPT, Claude…) and bring the answer back.')" />
            </flux:radio.group>
            <flux:error name="mode" />

        @elseif ($step === 3 && $askAi)
            <div>
                <h2 class="display text-3xl font-semibold text-white md:text-4xl">{{ __('Copy the prompt') }}</h2>
                <p class="mt-3 text-sm text-zinc-400">{{ __('Paste it into the agent you already use. It answers with a JSON you bring back in the next step. Zero token cost here.') }}</p>
            </div>
            <div x-data="{ copied: false, copy() { navigator.clipboard.writeText($refs.prompt.textContent).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000) }) } }" class="space-y-3">
                <div class="flex justify-end">
                    <flux:button type="button" size="sm" icon="clipboard" x-on:click="copy()"><span x-text="copied ? @js(__('Copied!')) : @js(__('Copy prompt'))">{{ __('Copy prompt') }}</span></flux:button>
                </div>
                <pre x-ref="prompt" class="font-mono max-h-96 overflow-auto whitespace-pre-wrap rounded-xl border border-white/10 bg-ink p-4 text-xs leading-relaxed text-zinc-300">{{ $prompt }}</pre>
            </div>

        @elseif ($step === 4 && $askAi)
            <div>
                <h2 class="display text-3xl font-semibold text-white md:text-4xl">{{ __('Paste the answer') }}</h2>
                <p class="mt-3 text-sm text-zinc-400">{{ __('It can come with text around it or inside ```json```. I clean it up and validate.') }}</p>
            </div>
            <flux:textarea wire:model="draft.ai_response" rows="14" class="font-mono text-xs" placeholder='{ "industry": "...", "keywords": [...], ... }' />
            {{-- Without a label Flux does not render the field error on its own. --}}
            <flux:error name="draft.ai_response" />

        @elseif ($step === 3)
            <div>
                <h2 class="display text-3xl font-semibold text-white md:text-4xl">{{ __('Segment & keywords') }}</h2>
                <p class="mt-3 text-sm text-zinc-400">{{ __('Keywords become Google Places searches, one per region. Be specific.') }}</p>
            </div>
            <flux:input wire:model="draft.industry" :label="__('Segment')" :placeholder="__('e.g. aesthetics')" />
            @include('livewire.icps.partials.tags', ['field' => 'keywords', 'label' => __('Keywords'), 'placeholder' => __('e.g. aesthetic clinic, day spa (Enter adds)')])

        @elseif ($step === 4)
            <div>
                <h2 class="display text-3xl font-semibold text-white md:text-4xl">{{ __('Regions & countries') }}</h2>
                <p class="mt-3 text-sm text-zinc-400">{{ __('With regions, each keyword is searched in each city/neighborhood. Without regions, the whole country.') }}</p>
            </div>
            @include('livewire.icps.partials.where')

        @elseif ($step === 5 && $askAi)
            <div>
                <h2 class="display text-3xl font-semibold text-white md:text-4xl">{{ __('Where to search') }}</h2>
                <p class="mt-3 text-sm text-zinc-400">{{ __('These are the places the agent suggested. Adjust regions and countries, or draw a radius on the map.') }}</p>
            </div>
            @include('livewire.icps.partials.where')

        @elseif ($step === 5)
            <div>
                <h2 class="display text-3xl font-semibold text-white md:text-4xl">{{ __('Size, job titles & signals') }}</h2>
                <p class="mt-3 text-sm text-zinc-400">{{ __('These criteria do not filter the search. They feed the qualification score later.') }}</p>
            </div>
            <flux:select wire:model="draft.company_size" :label="__('Company size')" :placeholder="__('Any')">
                @foreach ($sizes as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            @include('livewire.icps.partials.tags', ['field' => 'job_titles', 'label' => __('Decision-maker job titles'), 'placeholder' => __('e.g. owner, founder, manager (Enter adds)')])

            <flux:fieldset>
                <flux:legend>{{ __('Signals') }}</flux:legend>
                <div class="space-y-4">
                    <flux:switch wire:model="draft.has_website" :label="__('Must have its own website')" :description="__('Instagram/Facebook profiles do not count.')" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="draft.min_reviews" type="number" min="0" :label="__('Minimum reviews')" placeholder="10" />
                        <flux:input wire:model="draft.min_rating" type="number" min="0" max="5" step="0.1" :label="__('Minimum rating')" placeholder="4.0" />
                    </div>
                </div>
            </flux:fieldset>

        @else
            <div>
                <h2 class="display text-3xl font-semibold text-white md:text-4xl">{{ __('Review') }}</h2>
                <p class="mt-3 text-sm text-zinc-400">
                    @if ($askAi)
                        {{ __('Criteria extracted from the agent answer.') }} <flux:link as="button" type="button" wire:click="editManually">{{ __('Adjust manually') }}</flux:link>.
                    @else
                        {{ __('Check and adjust. Click a step above to edit.') }}
                    @endif
                </p>
            </div>
            <flux:input wire:model="draft.name" :label="__('ICP name')" :placeholder="__('e.g. Aesthetic clinics — São Paulo')" />

            <dl class="kv grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <dt>{{ __('Segment') }}</dt>
                    <dd>{{ $criteria->industry ?? '—' }}</dd>
                </div>
                <div>
                    <dt>{{ __('Size') }}</dt>
                    <dd>{{ $criteria->companySize?->value ?? __('Any') }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt>{{ __('Keywords') }}</dt>
                    <dd class="flex flex-wrap gap-1.5">@foreach ($criteria->keywords as $k)<span class="chip">{{ $k }}</span>@endforeach</dd>
                </div>
                <div>
                    <dt>{{ __('Countries') }}</dt>
                    <dd class="flex flex-wrap gap-1.5">@foreach ($criteria->countries as $c)<span class="chip">{{ \App\Support\Countries::name($c) }}</span>@endforeach</dd>
                </div>
                <div>
                    <dt>{{ __('Regions') }}</dt>
                    <dd class="flex flex-wrap gap-1.5">@forelse ($criteria->regions as $r)<span class="chip">{{ $r }}</span>@empty<span>{{ $criteria->areas ? '—' : __('Whole country') }}</span>@endforelse</dd>
                </div>
                @if ($criteria->areas)
                    <div class="sm:col-span-2">
                        <dt>{{ __('Map areas') }}</dt>
                        <dd class="flex flex-wrap gap-1.5">@foreach ($criteria->areas as $area)<span class="chip">{{ $area->displayName() }}</span>@endforeach</dd>
                    </div>
                @endif
                <div>
                    <dt>{{ __('Job titles') }}</dt>
                    <dd>{{ $criteria->jobTitles ? implode(', ', $criteria->jobTitles) : '—' }}</dd>
                </div>
                <div>
                    <dt>{{ __('Signals') }}</dt>
                    <dd>{{ $criteria->hasSignals() ? collect([$criteria->hasWebsite ? __('own website') : null, $criteria->minReviews !== null ? __('≥ :n reviews', ['n' => $criteria->minReviews]) : null, $criteria->minRating !== null ? __('rating ≥ :n', ['n' => $criteria->minRating]) : null])->filter()->implode(' · ') : '—' }}</dd>
                </div>
            </dl>

            @php($places = count($criteria->regions) + count($criteria->areas))
            @php($queries = count($criteria->keywords) * max(1, $places ?: count($criteria->countries)))
            <div class="font-mono rounded-xl border border-signal/30 bg-signal/6 px-4 py-3 text-xs leading-5 text-zinc-300">
                <span class="text-signal">→</span> {!! trans_choice('The search will run <strong>:count</strong> Google Places query (up to 60 results each)|The search will run <strong>:count</strong> Google Places queries (up to 60 results each)', $queries) !!}{{ $sourceCount > 1 ? __(', plus the other enabled sources') : '' }}.
            </div>
            @if ($needsPlacesKey)
                <x-integrations.missing-places-key />
            @endif
        @endif

        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-white/6 pt-6">
            <div class="flex gap-2">
                @if ($step > 1)
                    <flux:button type="button" variant="ghost" wire:click="back" icon="arrow-left">{{ __('Back') }}</flux:button>
                @endif
                <flux:button type="button" variant="subtle" wire:click="discard" :wire:confirm="__('Discard this ICP draft?')">{{ __('Discard') }}</flux:button>
            </div>
            @if ($step < \App\Livewire\Icps\Wizard::LAST_STEP)
                <flux:button type="submit" variant="primary" icon-trailing="arrow-right">{{ $step === 4 && $askAi ? __('Validate and continue') : __('Continue') }}</flux:button>
            @else
                <div class="flex gap-2">
                    <flux:button type="button" wire:click="save(false)">{{ __('Save draft') }}</flux:button>
                    <flux:button type="button" variant="primary" icon="play" wire:click="save(true)">{{ __('Save and find leads') }}</flux:button>
                </div>
            @endif
        </div>
    </form>
</div>
