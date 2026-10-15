<?php

namespace App\Providers;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\Enrichment\Clients\HunterClient;
use App\Domains\Enrichment\Clients\SiteScraper;
use App\Domains\LeadSources\LeadSourceManager;
use App\Domains\Outbound\OutboundConnectorResolver;
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
        RateLimiter::for('enrichment', function (): Limit {
            $limits = collect(['hunter', 'evolution'])
                ->filter(fn (string $client) => (bool) config("enrichment.{$client}.enabled"))
                ->map(fn (string $client) => max(1, (int) config("enrichment.{$client}.requests_per_minute", 60)));

            return $limits->isEmpty() ? Limit::none() : Limit::perMinute($limits->min());
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
