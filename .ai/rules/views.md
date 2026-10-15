---
paths:
  - 'resources/views/**'
---

# Views

## Themed design system: x-ui.* components plus Flux free
The signed-in app has a dark (default) and a light theme driven by users.theme → data-theme on <html>; the landing and auth screens are always dark. <html> always keeps class="dark" and the light theme works by flipping CSS tokens (white, ink, panel, zinc scale, signal), so write markup once with the dark-palette utilities (text-white, border-white/10, text-zinc-400, bg-panel…): never add light-mode base classes, dark: variants or per-theme markup. Use bg-paper only for content that must stay white in both themes (QR codes). Compose pages from <x-ui.page-header>, <x-ui.panel>, <x-ui.kv>, <x-ui.stat>, <x-ui.empty>, <x-ui.crumb>, <x-brand> and Flux free components only (no flux-pro). Use design tokens instead of arbitrary values: text-2xs, tracking-label/tracking-eyebrow, bg-ink, bg-panel, text-signal, shadow-glow, border-white/6; font-mono for anything data-like. Brand utilities (.eyebrow, .panel, .chip, .cell-grid, ...) are @utility, so override them with normal utilities, never with `!`. New text goes through __() and must be added to lang/pt_BR.json and lang/es.json.
