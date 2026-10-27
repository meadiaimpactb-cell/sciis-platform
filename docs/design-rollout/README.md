# Design v3 rollout — before / after

The "SCII Homepage v3" design system applied to every page and component (visual layer only: no logic, API or
routing changes). Screenshots are taken on the local stack (Arabic, 1440 px wide unless noted).

| Folder | What |
|---|---|
| `before/` | the previous design (commit `61c7e83`), default theme |
| `after/*-dark` | the new design, dark palette (the default) |
| `after/*-light` | the new design, light palette |
| `after/*-390` | the new design on a 390 px phone, dark |

Groups: **A** public marketing pages · **B** sign-in and registration · **C** member app (investor and project owner) ·
**D** admin.

## What changed

- **One theme for the site and the portals:** dark by default, light on request. The choice is kept in the browser
  and on the account, and applied before the first paint. Print is always light.
- **One token set:** `web/assets/css/tokens.css`, mapped into Tailwind (`web/tailwind.config.ts`). Pages use token
  classes only, so both palettes come from the same markup. Status colours are theme-aware hues.
- **Type scale, in falak.sa's proportions:**
  - sizes: display 50 · title 40 · subtitle 30 · heading 25 · card 20 · lead 18 · description 16 · body 14 · small 13;
  - nothing goes above 50 px, and reading text is never below 13 px.
- **Shared components:**
  - restyled to v3: pill buttons, chips, tabs, cards, inputs, tables, badges, modal, empty state, sidebar and topbar;
  - new: SectionLabel, GlassCard, OrbitBackdrop, ImpactMeter, IndicatorCard (props), StageTrack and DualConsent.
- **Live styleguide:** `/prototype`.
- **Contrast pairs:** listed in `tokens.css` and `docs/ACCESSIBILITY.md` (all text ≥ 4.5:1, controls ≥ 3:1).
