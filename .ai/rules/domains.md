---
paths:
  - 'app/Domains/**'
---

# Domains

## Business logic lives in app/Domains, framework-free
Domain code (Icp, LeadSources, Leads, Enrichment, Outbound, Webhooks) lives in app/Domains/<Domain>/. The service class sits at the domain root; types go in subfolders only when there is more than one: Contracts/, Data/ (DTOs and value objects), Exceptions/, Clients/ (HTTP), Drivers/. Keep it testable without DB or queue. Everything Laravel-shaped (Models, Jobs, Events, Listeners, Livewire, Http, Console, Policies, Support) stays in its default Laravel folder, never under Domains.

## Domain code uses native arrays, not Collections
Inside app/Domains, method signatures take and return native PHP arrays and Generators (with array-shape PHPDoc), not Illuminate Collections. Collections are fine transiently inside a method and in Livewire/Jobs, but never cross a domain boundary.
