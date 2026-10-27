# Accessibility — WCAG 2.1 AA (UX-04)

Audit date: 2026-10-25 · Scope: public site (home, about, impact investing, for investors / entrepreneurs, news, events,
FAQ, contact, legal, tools), auth screens (login, register, verify, reset) and the portals (investor, entrepreneur,
admin dashboards, files, forms, account, security).

## Method

`@axe-core/*` / Lighthouse are not installed in the repo (no new packages), so this audit is a **manual / static
review** of the shared layouts and components (every page is built from them) plus the main pages, with contrast
ratios computed from the design tokens (WCAG relative-luminance formula). Re-run an automated pass (axe DevTools or
Lighthouse in Chrome) against a staging build before launch and append the results here.

## Checklist and results

| # | Check (WCAG SC) | Result | Notes / fix |
|---|---|---|---|
| 1 | `lang` / `dir` on `<html>` (3.1.1, 3.1.2) | Pass | `app.vue` sets `lang` = ar / en and `dir` = rtl / ltr from the active locale; the language switch carries `lang` + `dir` of the target language. English blocks in Arabic forms use `dir="ltr"` / `lang="en"`. |
| 2 | Skip link (2.4.1) | **Fixed** | Public layout already had one. Portal: the header was *inside* `<main>`, so the skip link landed on the toolbar — `<main id="main">` now wraps the page content only. Auth layout: skip link added. Targets have `tabindex="-1"` so focus really moves. |
| 3 | Landmarks (1.3.1) | **Fixed** | Public: header / nav (labelled) / main / footer. Portal: labelled sidebar `aside` + `nav`, header outside `main` (was nested). Auth: `aside` + `main`. |
| 4 | Heading order (1.3.1, 2.4.6) | **Fixed** | One `h1` per page (`PageHeader`, `PageHero`); cards use `h2` (`SectionCard`), modals `h2`. The auth brand panel had an `h2` before the page `h1` — now a styled paragraph. |
| 5 | Labels on form controls (1.3.1, 3.3.2, 4.1.2) | Pass / **fixed** | `FormField` wires `label for`, `aria-invalid`, `aria-describedby` (error / help). Fixed: `aria-describedby` pointed at the help text even when the error replaced it (dangling id); checkbox `required` was not exposed (`aria-required`); chips / radio-card groups now announce "required". Notification-preference checkboxes were all labelled "E-mail" / "SMS" — they now include the category (row) name. |
| 6 | Keyboard operation (2.1.1) | **Fixed** | Tabs: arrows / Home / End with roving tabindex (existed). Radio cards (`FormField type="radio-cards"`): now one tab stop and arrow keys like native radios. Builder reorder uses up / down buttons (no drag-only interaction). The user menu used `role="menu"` without arrow-key support — now a plain disclosure list of links (Tab works, no false menu semantics); the bell popover is `aria-haspopup="dialog"`. |
| 7 | No keyboard trap / focus management in modals (2.1.2, 2.4.3) | **Fixed** | `Modal`: focus trap (Tab / Shift+Tab), Escape closes, `aria-modal`, labelled by its title; **focus now returns to the control that opened it**. SweetAlert2 dialogs manage focus themselves. Mobile menu button now reports `aria-expanded`. |
| 8 | Focus visible (2.4.7, 1.4.11) | **Fixed** | Global `:focus-visible` outline and `.kbd-focus` / `AppButton` rings used teal-500 (2.47:1 on white — below 3:1). Now teal-700: 4.88:1 on white, 4.55:1 on the page grey, 3.46:1 on the navy sidebar. Inputs: focus border teal-700 (was teal-500 + a 15 % ring). |
| 9 | Text contrast (1.4.3) | Pass / **fixed** | Tokens on white: ink 16.9, text 16.7, muted 5.49 (5.12 on page grey, 5.00 on navy-50), teal-700 4.88, navy 12.25, success 5.25, warning 5.02, danger 6.47, white on primary button 12.25, navy-900 on accent button 6.83, white 60 % / 50 % on navy-950 7.07 / 5.25. **Fixed:** status badges (`AppBadge`) — light statuses (draft / registered #94A3B8 2.37:1, needs-completion #D97706 2.86:1, several others 4.3:1) now use a darkened text / fill: every status ≥ 4.87:1. Notification timestamps (muted 80 %, 3.61:1) now full muted. |
| 10 | Non-text contrast of controls (1.4.11) | **Fixed** | Input / select / textarea outline was #E3E7F0 (1.24:1). New token `--c-input-border` #858EAB: 3.25:1 on white, 3.03:1 on the page grey. Card borders stay light (cards are not controls). |
| 11 | Images / icons (1.1.1) | Pass | Logo `img` has the platform name; decorative marks / SVG icons are `alt=""` / `aria-hidden`; CMS hero images carry their Arabic / English alt text; icon-only buttons have `aria-label`. |
| 12 | Tables (1.3.1) | Pass | `DataTable` / `.table-base`: `<thead>` with `th scope="col"`, `aria-sort` on sortable columns, sort buttons labelled by the column. |
| 13 | ARIA references (4.1.2) | **Fixed** | `Tabs` always set `aria-controls="panel-…"` although most pages render no such panel — now only when the panel exists. Home hero dots used `role="tab"` without panels — now buttons with `aria-current`. |
| 14 | Live regions / status messages (4.1.3) | Pass | Toasts (`utils/notify.ts`): `role="alert"`, assertive for errors, polite otherwise; `FormAlert` `role="alert"`; field errors `role="alert"`; autosave / builder status lines `aria-live="polite"`; `InlineAlert` `role="status"`. |
| 15 | Moving content (2.2.2) | **Fixed** | The home hero cross-fades every 5 s (off with `prefers-reduced-motion`). Added a pause / play button; rotation also stops on hover and keyboard focus. |
| 16 | Reflow / zoom (1.4.4, 1.4.10) | Pass (static) | Fluid layouts, `rem` type scale, tables scroll horizontally inside their card, no fixed-height text boxes. Verify at 400 % zoom on staging. |
| 17 | Error identification (3.3.1, 3.3.3) | Pass | Client rules (`utils/validation.ts`) and API 422 messages land under the field (Arabic / English), with a form-level message for rate limits. |
| 18 | Consistent navigation / identification (3.2.3, 3.2.4) | Pass | Shared header / sidebar / footer across pages. |
| 19 | Themes (design v3, BRD-04) | Pass | Dark (default) and light, site and portals. Text pairs on the card colour (dark #203068 / light white): ink 12.5 / 18.2, text 8.0 / 9.1, muted 5.5 / 5.9, accent text 7.2 / 4.9, success 7.2 / 5.2, warning 6.4 / 5.0, danger 4.8 / 6.1, #07102A on the accent #58B0A8 7.3, input outline 3.6 / 3.2 (≥ 3:1). See "Themes" below. |
| 20 | Digits | Pass | Latin digits only (platform rule), including Hijri dates (`nu-latn`). |

## Themes (design v3, BRD-04)

One theme for the public site and the portals: dark by default, light on request (header / topbar switch, or
"My account → Display": dark / light / device). The choice lives on `<html data-theme>` and in the browser
(`scii-theme`), is applied before the first paint, and is saved on the account when signed in.

How: every colour is a token in `web/assets/css/tokens.css` — the v3 names (`--bg0`, `--fg`, `--t2` …) and RGB
channels (`--c-*`) that the Tailwind colours read, so utilities and their opacity modifiers follow the theme without
`dark:` classes. Status hues (`--hue-*`) are lighter on the dark palette so dots, bars and badge text stay visible.
`data-theme="light"` on an element makes a light island (the printable agreement; QR codes); printing always uses the
light palette.

Not themed: email templates and PDFs (always light).

## Remaining / to verify

- Lighthouse 13.5 accessibility, 2026-10-27, local build, default (dark) theme, mobile: `/`, `/about`,
  `/for-investors`, `/faq`, `/auth/login`, `/en/contact` — 100 each. Still to run: one signed-in page per portal
  (Lighthouse can't sign in by itself — use DevTools on a signed-in session).
- Screen-reader pass (NVDA + Chrome, VoiceOver + Safari) on registration, the opportunity form and the membership file.
- `FilterSidebar` shows zero-count options in muted 60 % (2.6:1); acceptable only while they are treated as disabled —
  consider full muted text.
- Native date inputs show digits in the browser / OS locale (outside the page's control).
- Charts (`BarChart`, `CompletionRing`) expose their values as text / `aria-label`; colour is never the only signal there, but
  the evaluation comparison colours should be re-checked against 1.4.11 when the design changes.
