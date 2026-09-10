---
paths:
  - 'app/Enums/**'
---

# Enums

## Enums are string-backed with label() and color()
Domain enums are `enum X: string` with TitleCase cases, a label() method returning __('...') for the UI, and color() returning a Flux badge color where the enum is shown as a badge. Add options() (value => label) when the enum feeds a select. Persist the enum value string in the DB and cast on the model; never compare raw strings in code.
