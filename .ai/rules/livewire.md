---
paths:
  - 'app/Livewire/**'
---

# Livewire

## Livewire components and Jobs are thin: call the domain
Livewire actions and Job handle() methods only authorize, validate, call a Domains service, and dispatch jobs/events/toasts. Put parsing, scoring, dedup, mapping and HTTP calls in app/Domains, not in the component or job. Component helper methods that the view needs are protected and their result is passed through render(); do not leave public helper methods callable as actions.

## Page component conventions
Pages are class components in app/Livewire/<Area>/ with views in resources/views/livewire/<area>/, registered with Route::livewire(). Use #[Title] (or ->title() in render), Gate::authorize in mount() AND in every mutating action, validate action arguments with Validator::make + Rule::enum/Rule::in, Flux::toast for feedback, wire:navigate for internal links, #[Url] for filter state. Every @foreach in a Livewire view carries wire:key.
