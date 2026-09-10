---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Jobs are thin, idempotent and never fan out from a request
Job handle() only loads models, calls a Domains service and dispatches follow-up jobs/events. Jobs must be safe to re-run (check status columns, ShouldBeUnique, or a pre-recorded lead_sources row). Bulk work goes through a single batch job that iterates with chunkById and dispatches per-item jobs; never loop-dispatch from a Livewire action. Set retry_after >= the longest job $timeout (SourceSearchJob 1800s). Paid, non-idempotent POSTs (WhatsApp) use $tries = 1.
