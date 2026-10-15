---
paths:
  - 'app/Domains/**/Clients/**'
---

# Clients

## HTTP clients are bound in AppServiceProvider with their config array; keys come per user
Clients (HunterClient, EvolutionClient, SiteScraper) take a plain config array in the constructor and are bound in AppServiceProvider::register() with config('enrichment.<client>'), which holds only tuning (RPM, cost), never API keys. Keys are BYOK: callers pass the project owner's credentials with `$client->withCredentials($user->credentialsFor(IntegrationProvider::X))`, which returns a new instance; lead-source drivers get them through `LeadSourceManager::withCredentials($user->integrationCredentials())`. Clients never call config() or app() themselves. Paid clients expose isConfigured() and a free verify() call used by IntegrationVerifier. Every Http call sets connectTimeout(3) plus a total timeout; paid clients keep a RateLimiter throttle keyed per API key as a safety net, but pacing belongs to the RateLimited job middleware.
