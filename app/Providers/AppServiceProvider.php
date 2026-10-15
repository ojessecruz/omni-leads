<?php

namespace App\Providers;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\Enrichment\Clients\HunterClient;
use App\Domains\Enrichment\Clients\SiteScraper;
use App\Domains\LeadSources\LeadSourceManager;
use App\Domains\Outbound\OutboundConnectorResolver;
use App\Enums\IntegrationProvider;
use App\Jobs\EnrichLeadJob;
use App\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LeadSourceManager::class, fn ($app) => new LeadSourceManager($app));
        $this->app->bind(SiteScraper::class, fn () => new SiteScraper(config('enrichment.site_scrape', [])));
        $this->app->bind(HunterClient::class, fn () => new HunterClient(config('enrichment.hunter', [])));
        $this->app->bind(EvolutionClient::class, fn () => new EvolutionClient(config('enrichment.evolution', [])));
        $this->app->bind(OutboundConnectorResolver::class, fn () => new OutboundConnectorResolver(config('outbound', [])));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiters();
    }

    /** Enrichment queue: the lowest RPM among enabled paid clients (site scrape only: effectively unlimited). */
    protected function configureRateLimiters(): void
    {
        // Per project owner: each user's paid clients run on their own keys and quotas.
        RateLimiter::for('enrichment', function (EnrichLeadJob $job): Limit {
            $owner = Lead::with('project.user.integrations')->find($job->leadId)?->project->user;

            $limits = collect([IntegrationProvider::Hunter, IntegrationProvider::Evolution])
                ->filter(fn (IntegrationProvider $provider) => $owner !== null && $owner->hasIntegration($provider))
                ->map(fn (IntegrationProvider $provider) => max(1, (int) config("enrichment.{$provider->value}.requests_per_minute", 60)));

            return $limits->isEmpty() ? Limit::none() : Limit::perMinute($limits->min())->by('user:'.$owner?->id);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
