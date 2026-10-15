<?php

namespace App\Livewire\Icps;

use App\Domains\Icp\Clients\PlaceLookupClient;
use App\Domains\Icp\Data\GeoArea;
use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\Icp\Exceptions\IcpParseException;
use App\Domains\Icp\IcpJsonParser;
use App\Enums\CompanySize;
use App\Enums\IcpDraftMode;
use App\Enums\IcpStatus;
use App\Jobs\DispatchIcpSearchJob;
use App\Models\Icp;
use App\Models\IcpDraft;
use App\Models\Project;
use App\Support\Countries;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * ICP wizard. Persists to icp_drafts on every next() — a refresh loses nothing.
 *
 * Manual: 1 Context · 2 Mode · 3 Segment & keywords · 4 Where (regions, map areas, countries) · 5 Size, job titles & signals · 6 Review
 * Ask AI: 1 Context · 2 Mode · 3 Prompt · 4 Answer · 5 Where (adjust what the agent suggested) · 6 Review
 */
#[Title('New ICP')]
class Wizard extends Component
{
    /** Labels are translation keys (__()). */
    public const array MANUAL_STEPS = [
        1 => 'Context',
        2 => 'Mode',
        3 => 'Segment',
        4 => 'Where',
        5 => 'Profile',
        6 => 'Review',
    ];

    public const array ASK_AI_STEPS = [
        1 => 'Context',
        2 => 'Mode',
        3 => 'Prompt',
        4 => 'Answer',
        5 => 'Where',
        6 => 'Review',
    ];

    public const int LAST_STEP = 6;

    public const int MAX_AREAS = 20;

    /** Place lookups per user per minute (each one is a paid Places request). */
    public const int PLACE_LOOKUPS_PER_MINUTE = 20;

    public Project $project;

    public int $step = 1;

    public string $mode = 'manual';

    /** @var array<string, mixed> */
    public array $draft = [];

    /** @var array<string, string> */
    public array $tagInput = ['keywords' => '', 'regions' => '', 'job_titles' => ''];

    public string $placeQuery = '';

    /** @var list<array{label: string, lat: float, lng: float}> */
    public array $placeResults = [];

    public function mount(Project $project): void
    {
        Gate::authorize('update', $project);

        $this->project = $project;

        $saved = $this->findDraft();

        $this->draft = $this->defaults();
        $this->mode = IcpDraftMode::Manual->value;

        if ($saved !== null) {
            $this->draft = array_merge($this->draft, $saved->draft);
            $this->step = $saved->step;
            $this->mode = $saved->mode->value;
        }
    }

    public function next(): void
    {
        Gate::authorize('update', $this->project);

        $this->validateStep($this->step);

        if ($this->step === 1) {
            $this->project->update([
                'product_description' => $this->draft['product_description'],
                'problem' => $this->draft['problem'],
                'current_customers' => $this->draft['current_customers'],
                'avg_ticket' => $this->draft['avg_ticket'],
            ]);
        }

        if ($this->isAskAi() && $this->step === 4) {
            $this->applyAiResponse();
        }

        $next = $this->nextStep();

        if ($next === self::LAST_STEP && blank($this->draft['name'])) {
            $this->draft['name'] = $this->suggestName();
        }

        $this->step = $next;
        $this->persistDraft();
    }

    public function back(): void
    {
        Gate::authorize('update', $this->project);

        $this->step = $this->previousStep();
        $this->persistDraft();
    }

    /** Leaves Ask AI to adjust the criteria in the manual steps. */
    public function editManually(): void
    {
        Gate::authorize('update', $this->project);

        $this->mode = IcpDraftMode::Manual->value;
        $this->step = 3;
        $this->persistDraft();
    }

    protected function isAskAi(): bool
    {
        return $this->mode === IcpDraftMode::AskAi->value;
    }

    /** @return array<int, string> */
    protected function steps(): array
    {
        return $this->isAskAi() ? self::ASK_AI_STEPS : self::MANUAL_STEPS;
    }

    protected function prompt(): string
    {
        return trim(view('prompts.icp', ['project' => $this->project])->render());
    }

    protected function nextStep(): int
    {
        $keys = array_keys($this->steps());
        $pos = array_search($this->step, $keys, true);

        return $keys[min(($pos === false ? 0 : $pos) + 1, count($keys) - 1)];
    }

    protected function previousStep(): int
    {
        $keys = array_keys($this->steps());
        $pos = array_search($this->step, $keys, true);

        return $keys[max(($pos === false ? 0 : $pos) - 1, 0)];
    }

    protected function applyAiResponse(): void
    {
        try {
            $criteria = app(IcpJsonParser::class)->parse((string) $this->draft['ai_response']);
        } catch (IcpParseException $e) {
            throw ValidationException::withMessages(['draft.ai_response' => $e->getMessage()]);
        }

        $this->draft = array_merge($this->draft, [
            'industry' => $criteria->industry ?? '',
            'keywords' => $criteria->keywords,
            'countries' => $criteria->countries,
            'regions' => $criteria->regions,
            // Agents rarely answer with coordinates: keep the areas already drawn unless the answer brings its own.
            'areas' => $criteria->areas !== [] ? $this->areasToArray($criteria->areas) : $this->draft['areas'],
            'company_size' => $criteria->companySize?->value,
            'job_titles' => $criteria->jobTitles,
            'has_website' => (bool) $criteria->hasWebsite,
            'min_reviews' => $criteria->minReviews,
            'min_rating' => $criteria->minRating,
        ]);
    }

    public function goTo(int $step): void
    {
        Gate::authorize('update', $this->project);

        if ($step < $this->step && array_key_exists($step, $this->steps())) {
            $this->step = $step;
            $this->persistDraft();
        }
    }

    public function addTag(string $field): void
    {
        Gate::authorize('update', $this->project);

        if (! array_key_exists($field, $this->tagInput)) {
            return;
        }

        $values = preg_split('/[,\n]/', $this->tagInput[$field]) ?: [];

        foreach ($values as $value) {
            $value = trim($value);

            if ($value !== '' && ! in_array($value, $this->draft[$field], true)) {
                $this->draft[$field][] = $value;
            }
        }

        $this->tagInput[$field] = '';
    }

    public function removeTag(string $field, int $index): void
    {
        Gate::authorize('update', $this->project);

        if (isset($this->draft[$field][$index])) {
            unset($this->draft[$field][$index]);
            $this->draft[$field] = array_values($this->draft[$field]);
        }
    }

    /** Finds an address/neighborhood/city to center the map on. */
    public function searchPlace(PlaceLookupClient $places): void
    {
        Gate::authorize('update', $this->project);

        $this->validate(['placeQuery' => ['required', 'string', 'min:2', 'max:120']]);

        $this->placeResults = [];
        $limiterKey = 'place-lookup:'.auth()->id();

        if (RateLimiter::tooManyAttempts($limiterKey, self::PLACE_LOOKUPS_PER_MINUTE)) {
            $this->addError('placeQuery', __('Too many searches. Wait a minute or click on the map.'));

            return;
        }

        RateLimiter::hit($limiterKey);

        try {
            $this->placeResults = $places->search($this->placeQuery, str_replace('_', '-', app()->getLocale()));
        } catch (RuntimeException $e) {
            report($e);
            $this->addError('placeQuery', __('Could not search places right now. Click on the map instead.'));

            return;
        }

        if ($this->placeResults === []) {
            $this->addError('placeQuery', __('No place found. Try another name or click on the map.'));
        }
    }

    /** Returns whether the area was added (the map keeps the pending circle otherwise). */
    public function addArea(mixed $lat, mixed $lng, mixed $radiusKm, mixed $label = ''): bool
    {
        Gate::authorize('update', $this->project);

        $data = Validator::make(
            ['lat' => $lat, 'lng' => $lng, 'radius_km' => $radiusKm, 'label' => $label],
            $this->areaRules(''),
        )->validate();

        if (count($this->draft['areas']) >= self::MAX_AREAS) {
            $this->addError('draft.areas', __('Up to :max areas per ICP.', ['max' => self::MAX_AREAS]));

            return false;
        }

        $area = GeoArea::fromArray($data);

        if ($area === null) {
            return false;
        }

        $this->draft['areas'][] = $area->toArray();
        $this->placeResults = [];
        $this->placeQuery = '';

        return true;
    }

    public function removeArea(int $index): void
    {
        Gate::authorize('update', $this->project);

        if (isset($this->draft['areas'][$index])) {
            unset($this->draft['areas'][$index]);
            $this->draft['areas'] = array_values($this->draft['areas']);
        }
    }

    public function save(bool $run = false): void
    {
        Gate::authorize('update', $this->project);

        foreach (array_keys($this->steps()) as $step) {
            $this->validateStep($step);
        }

        $icp = Icp::create([
            'project_id' => $this->project->id,
            'name' => $this->draft['name'],
            'status' => $run ? IcpStatus::Active : IcpStatus::Draft,
            'criteria' => $this->criteria()->toArray(),
        ]);

        $this->findDraft()?->delete();

        if ($run) {
            DispatchIcpSearchJob::dispatch($icp->id);
        }

        $this->redirectRoute('icps.show', $icp, navigate: true);
    }

    public function discard(): void
    {
        Gate::authorize('update', $this->project);

        $this->findDraft()?->delete();

        $this->redirectRoute('projects.show', $this->project, navigate: true);
    }

    protected function criteria(): IcpCriteria
    {
        return IcpCriteria::fromArray([
            'industry' => $this->draft['industry'],
            'keywords' => $this->draft['keywords'],
            'countries' => $this->draft['countries'],
            'regions' => $this->draft['regions'],
            'areas' => $this->draft['areas'],
            'company_size' => $this->draft['company_size'],
            'job_titles' => $this->draft['job_titles'],
            'signals' => [
                'has_website' => $this->draft['has_website'] ? true : null,
                'min_reviews' => $this->draft['min_reviews'],
                'min_rating' => $this->draft['min_rating'],
            ],
        ]);
    }

    public function render(): View
    {
        $askAi = $this->isAskAi();

        return view('livewire.icps.wizard', [
            'countries' => Countries::options(),
            'sizes' => CompanySize::options(),
            'askAi' => $askAi,
            'steps' => $this->steps(),
            'prompt' => $askAi && $this->step === 3 ? $this->prompt() : null,
            'criteria' => $this->step === self::LAST_STEP ? $this->criteria() : null,
        ]);
    }

    protected function validateStep(int $step): void
    {
        $rules = $this->rulesFor($step);

        if ($rules !== []) {
            $this->validate($rules);
        }
    }

    /** @return array<string, list<mixed>> */
    protected function rulesFor(int $step): array
    {
        return match ($step) {
            1 => [
                'draft.product_description' => ['required', 'string', 'min:10', 'max:2000'],
                'draft.problem' => ['required', 'string', 'min:10', 'max:2000'],
                'draft.current_customers' => ['nullable', 'string', 'max:2000'],
                'draft.avg_ticket' => ['nullable', 'string', 'max:60'],
            ],
            2 => [
                'mode' => ['required', Rule::enum(IcpDraftMode::class)],
            ],
            3 => $this->isAskAi() ? [] : [
                'draft.industry' => ['required', 'string', 'max:80'],
                'draft.keywords' => ['array', 'min:1', 'max:30'],
                'draft.keywords.*' => ['string', 'max:80'],
            ],
            4 => $this->isAskAi() ? [
                'draft.ai_response' => ['required', 'string', 'max:20000'],
            ] : $this->whereRules(),
            5 => $this->isAskAi() ? $this->whereRules() : [
                'draft.company_size' => ['nullable', Rule::enum(CompanySize::class)],
                'draft.job_titles' => ['array', 'max:20'],
                'draft.job_titles.*' => ['string', 'max:60'],
                'draft.has_website' => ['boolean'],
                'draft.min_reviews' => ['nullable', 'integer', 'min:0', 'max:100000'],
                'draft.min_rating' => ['nullable', 'numeric', 'between:0,5'],
            ],
            6 => [
                'draft.name' => ['required', 'string', 'max:120'],
            ],
            default => [],
        };
    }

    /** @return array<string, list<mixed>> */
    protected function whereRules(): array
    {
        return [
            'draft.countries' => ['array', 'min:1', 'max:20'],
            'draft.countries.*' => [Rule::in(array_keys(Countries::ALL))],
            'draft.regions' => ['array', 'max:50'],
            'draft.regions.*' => ['string', 'max:80'],
            'draft.areas' => ['array', 'max:'.self::MAX_AREAS],
            ...$this->areaRules('draft.areas.*.'),
        ];
    }

    /** @return array<string, list<mixed>> */
    protected function areaRules(string $prefix): array
    {
        return [
            "{$prefix}lat" => ['required', 'numeric', 'between:-90,90'],
            "{$prefix}lng" => ['required', 'numeric', 'between:-180,180'],
            "{$prefix}radius_km" => ['required', 'numeric', 'between:'.GeoArea::MIN_RADIUS_KM.','.GeoArea::MAX_RADIUS_KM],
            "{$prefix}label" => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @param  list<GeoArea>  $areas
     * @return list<array<string, mixed>>
     */
    protected function areasToArray(array $areas): array
    {
        return array_map(fn (GeoArea $area) => $area->toArray(), $areas);
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return [
            'product_description' => $this->project->product_description ?? '',
            'problem' => $this->project->problem ?? '',
            'current_customers' => $this->project->current_customers ?? '',
            'avg_ticket' => $this->project->avg_ticket ?? '',
            'name' => '',
            'ai_response' => '',
            'industry' => '',
            'keywords' => [],
            'countries' => ['BR'],
            'regions' => [],
            'areas' => [],
            'company_size' => null,
            'job_titles' => [],
            'has_website' => false,
            'min_reviews' => null,
            'min_rating' => null,
        ];
    }

    protected function suggestName(): string
    {
        $areaLabel = trim((string) preg_replace('/,.*$/', '', (string) ($this->draft['areas'][0]['label'] ?? '')));

        $where = $this->draft['regions'][0]
            ?? ($areaLabel !== '' ? $areaLabel : null)
            ?? Countries::name($this->draft['countries'][0] ?? 'BR');

        return trim("{$this->draft['industry']} — {$where}");
    }

    protected function persistDraft(): void
    {
        IcpDraft::updateOrCreate(
            ['project_id' => $this->project->id, 'user_id' => auth()->id()],
            ['step' => $this->step, 'mode' => $this->mode, 'draft' => $this->draft],
        );
    }

    protected function findDraft(): ?IcpDraft
    {
        return IcpDraft::query()
            ->where('project_id', $this->project->id)
            ->where('user_id', auth()->id())
            ->first();
    }
}
