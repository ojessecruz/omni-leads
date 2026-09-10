---
paths:
  - 'app/Domains/**/Data/**'
---

# Data

## DTOs are readonly classes with fromArray()/toArray()
Data objects (LeadDTO, IcpCriteria, EnrichmentResult) are plain `final readonly class` with promoted constructor properties, a static fromArray() that ignores unknown keys, and toArray(). No spatie/laravel-data, no Fluent, no arrays-as-objects. Unknown or extra input keys are dropped silently, never thrown.
