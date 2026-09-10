---
paths:
  - 'app/Models/**'
---

# Models

## Models: @property docblocks, #[Fillable], casts() with enums
Every model declares a class docblock with @property lines for all columns (Larastan level relies on it), mass assignment through the #[Fillable([...])] attribute, and casts() returning enum classes for status columns, 'array' for jsonb and 'datetime' for timestamps. Relations carry generic return types (/** @return BelongsTo<Project, $this> */). Query scopes are scopeX methods used as Lead::query()->inIcp($id); prefer them over pivot relations when a query needs selectRaw.
