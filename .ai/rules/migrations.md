---
paths:
  - 'database/migrations/**'
---

# Migrations

## Migrations: string columns for enums, jsonb for documents, index every FK
Enum-like columns are plain string columns (cast to the PHP enum on the model), never DB enum types. Structured data (criteria, raw_payload, params, draft) is jsonb. Postgres does not index foreign keys automatically: add ->index() on every foreignId that is filtered or joined. Do not bundle a data backfill with a new unique constraint in the same migration.
