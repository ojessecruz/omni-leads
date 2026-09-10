---
paths:
  - 'app/Domains/**/Clients/**'
---

# Clients

## HTTP clients are bound in AppServiceProvider with their config array
Clients (HunterClient, EvolutionClient, SiteScraper) take a plain config array in the constructor and are bound in AppServiceProvider::register() with config('enrichment.<client>'). They never call config() or app() themselves. Every Http call sets connectTimeout(3) plus a total timeout; paid clients keep a RateLimiter throttle as a safety net, but pacing belongs to the RateLimited job middleware.
