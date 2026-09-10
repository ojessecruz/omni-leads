---
paths:
  - 'tests/**'
---

# Tests

## Tests: Pest feature tests with factories and framework fakes
Write Pest `test('descrição em português', ...)` feature tests under tests/Feature/<Area>/. Build state with model factories (check factory states first), isolate external HTTP with Http::fake keyed by URL, and queues/events with Queue::fake / Event::fake. Exercise jobs by calling handle() directly with app()-resolved services; exercise pages with Livewire::actingAs()->test(). Do not mock the project's own classes with Mockery. The starter-kit PHPUnit tests in tests/Feature/Auth and Settings stay in their original style. One Http::fake per test: a second call merges stubs and the first matching URL wins.
