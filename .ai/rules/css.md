---
paths:
  - 'resources/css/**'
---

# Css

## Flux is restyled with unlayered [data-flux-*] rules
Flux overrides in app.css target [data-flux-*] attributes and stay outside any @layer on purpose (flux.css does the same; inside a layer they lose to Flux's own utility classes). Do not add !important there. Brand utilities are registered with @utility and design tokens in @theme. When overriding [data-flux-control] use background-color, never the background shorthand (it wipes the select chevron background-image). The lime accent must also be re-declared in `@layer theme .dark` because flux.css forces a white accent in dark mode. Hairlines and overlays must reference tokens (color-mix(in oklab, var(--color-white) X%, transparent)), never literal rgba(255,255,255,…), so the light theme (html[data-theme="light"] token block) flips them. Modal styling targets `[data-flux-modal] > dialog` (data-flux-modal-content only exists with scroll="body").
