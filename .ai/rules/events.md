---
paths:
  - 'app/Events/**'
---

# Events

## Side effects go through domain events and auto-discovered listeners
Status changes and search completion dispatch an event from app/Events (LeadStatusChanged, SearchFinished, ...). Listeners live in app/Listeners and are auto-discovered by type-hint; do not register them in a provider. Webhooks are delivered only by the DispatchWebhooks listener, never dispatched directly from a job or component.
