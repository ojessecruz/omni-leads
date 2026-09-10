---
paths:
  - 'resources/views/**'
---

# Views

## Dark-only design system: x-ui.* components plus Flux free
The app is dark-only (html class="dark", no @fluxAppearance, no theme toggle): never write light-mode base classes or dark: variants. Compose pages from <x-ui.page-header>, <x-ui.panel>, <x-ui.kv>, <x-ui.stat>, <x-ui.empty>, <x-ui.crumb>, <x-brand> and Flux free components only (no flux-pro). Use design tokens instead of arbitrary values: text-2xs, tracking-label/tracking-eyebrow, bg-ink, bg-panel, text-signal, shadow-glow, border-white/6; font-mono for anything data-like. Brand utilities (.eyebrow, .panel, .chip, .cell-grid, ...) are @utility, so override them with normal utilities, never with `!`. New text goes through __() and must be added to lang/pt_BR.json and lang/es.json.
