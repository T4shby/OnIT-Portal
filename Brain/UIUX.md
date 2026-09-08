# On IT Portal Design System

The portal shares the **onit.ltd marketing design system**. Canonical reference files live in [Brain/design/](design/).

| File | Contents |
|---|---|
| [design/SKILL.md](design/SKILL.md) | Brand rules, non-negotiables, page structure, animations |
| [design/design-system.md](design/design-system.md) | Colours, typography, heading stack, spacing |
| [design/components.md](design/components.md) | Cards, CTAs, agenda, tables, FAQ patterns |

**Source of truth for marketing:** `On IT Updated - Documents/Marketing/Website/Claude Code/` (sync into `Brain/design/` when updated).

---

## Brand rules (portal)

These mirror [design/SKILL.md](design/SKILL.md). Apply on every portal screen.

1. **Background** `#011926` with grid overlay and subtle orange radial glow (`.onit-bg` + `.grid-overlay`)
2. **Accent** `#FF7000` for CTAs, rules, hover, labels. Never paragraph text.
3. **Fonts** Barlow Condensed (headings, buttons, labels) + Barlow (body)
4. **Heading stack** white block then orange block, left-aligned, preceded by orange rule
5. **Cards** solid `#071f2e`, border `#0f3048`, square corners, orange top line on hover
6. **Buttons** square corners only. `.cta-btn` (primary) and `.cta-btn-ghost` (secondary)
7. **No** rounded cards, pill buttons, glass surfaces, em dashes, or light grey SaaS backgrounds
8. **Body text** `rgba(255,255,255,0.82)` / `0.62` via `text-white/80` and `text-white/60`

---

## Portal vs marketing

Dashboard glance is the same dark On IT shell (Barlow, square cards, heading stack). Do not load a second font (Poppins) or round the home cards.

| Marketing | Portal |
|---|---|
| Standalone HTML, Tailwind CDN | Laravel Blade, Vite, `@tailwind` |
| No nav/footer (GHL embed) | Client header nav + admin sidebar |
| Hero with bg image + wipe animations | Dashboard uses heading stack only |
| Lucide icon boxes in cards | Partner logos (SuperOps, Pax8) in portal link tiles |
| `max-w-5xl` | `max-w-portal` (64rem / 1024px) |

Marketing-only patterns (hero image sections, FAQ accordion, GHL forms, scroll reveal JS) are documented in [design/components.md](design/components.md) for reference but are **not** used on the portal unless explicitly added.

---

## Colour tokens

Defined in `tailwind.config.js` and [design/design-system.md](design/design-system.md).

| Token | Hex | Usage |
|---|---|---|
| `onit` | `#FF7000` | Accent, CTAs, rules, table headers |
| `onit-hover` | `#e56300` | Button hover |
| `onit-ink` | `#011926` | Shell, header, sidebar |
| `onit-surface` | `#071f2e` | Cards, panels, tables |
| `onit-surface-hover` | `#0a2a3f` | Card hover |
| `onit-border` | `#0f3048` | Card and panel borders |
| `onit-muted` | `#0a2536` | Rare lighter surface |

---

## Typography

See [design/design-system.md](design/design-system.md) for full size/weight table.

| Element | Font | Portal class / utility |
|---|---|---|
| Section headings | Barlow Condensed 800 | `.section-heading-white` / `.section-heading-orange` |
| Card titles | Barlow Condensed 700 | `.portal-card-title` |
| Labels | Barlow Condensed 700 | `.portal-label` |
| Body | Barlow 300 | `.portal-body` |
| Muted | Barlow 300 | `.portal-body-muted` |
| Nav links | Barlow Condensed 600 | `.portal-nav-link` |

Fonts loaded in layout Blade files via Google Fonts (`Barlow` + `Barlow Condensed`).

---

## Heading stack (portal)

Marketing pattern from [design/design-system.md](design/design-system.md). Portal uses simplified class names (no wipe animation).

```blade
<div class="orange-rule"></div>
<div class="heading-stack">
    <h1 class="section-heading-white">Line one</h1>
    <h1 class="section-heading-orange">Line two</h1>
</div>
```

Rules: white line first, orange last, left-aligned, orange rule above.

---

## CSS classes (`resources/css/app.css`)

| Class | Marketing equivalent | Purpose |
|---|---|---|
| `.onit-bg` / `.grid-overlay` | Same | Global dark background |
| `.orange-rule` | Same | 40x3px rule above headings |
| `.heading-stack` | Same | White + orange heading blocks |
| `.section-heading-white` / `-orange` | `.section-heading.white-bg` / `.orange-bg` | Heading stack lines |
| `.benefit-card` | Same | Cards, panels, admin cards |
| `.portal-link` | `.benefit-card` (clickable) | Portal service tiles |
| `.cta-btn` / `.cta-btn-ghost` | Same | Primary and secondary actions |
| `.portal-body` / `.portal-body-muted` | Inline rgba styles | Body copy on dark |
| `.portal-label` | Badge / label | Orange uppercase label |
| `.admin-table-wrap` | `.sla-table` wrapper | Admin data tables |
| `.admin-input` | - | Form fields on dark |
| `.form-card` | Same | Login panel |
| `.onboarding-guide` | FAQ accordion + agenda list | Client setup guide ([design/components.md](design/components.md)) |
| `.onboarding-manual` | Install-manual blocks | Prerequisites, numbered parts, verify section in checklist |
| `.support-list` | Same | Bulleted instructions in cards (legacy / field help) |
| `.agenda-title` | Same | Step titles in onboarding guide |

---

**Layouts (2026-08-18):** live shells are `resources/views/components/layouts/{app,admin}.blade.php` via `x-app-layout` / `x-admin-layout`. The old Breeze copies under `resources/views/layouts/` were unused and removed.

## Layout

### Client shell (`components/layouts/app.blade.php`)

- `.portal-shell` = `.onit-bg` + flex column, min-height 100dvh
- `.portal-header` = `bg-onit-ink`, border `onit-border`
- Main content: `max-w-portal mx-auto`, grid overlay behind content
- Nav: Dashboard link white by default, orange on hover/active (`.portal-nav-link-active`)

### Admin shell (`components/layouts/admin.blade.php`)

- `.admin-shell` + sidebar `bg-onit-ink`
- `.admin-main` for content area
- `.admin-card` = benefit card styling for forms and detail panels

### Login (`auth/login.blade.php`)

- `.login-panel` with grid overlay
- `.form-card` for the sign-in panel
- Microsoft SSO via `.cta-btn`

---

## Portal link cards

Dashboard tiles for SuperOps, Pax8, etc. Use `.portal-link` (extends `.benefit-card`).

- Partner **wordmark logos** in `public/images/logos/` (not Lucide icon boxes)
- No opaque icon boxes or decorative gradients
- Component: `resources/views/components/portal-link-brand.blade.php`

---

## Admin tables

Use `.admin-table-wrap` for list pages. Header styling matches SLA table pattern (orange uppercase headers).

Status badges (`<x-badge>`) are allowed for data display only. Not pill-shaped marketing badges.

---

## Anti-patterns

Do not use on the portal:

- Pill badges with dots, arrow CTAs, em dashes in copy
- Inter, DM Sans, or generic SaaS light mode
- `rounded-xl` cards or buttons
- Transparent/glass cards (grid must not bleed through)
- Invented stats or decorative gradients on text
- Centre-aligned section headings (unless a specific brief requires it)

---

## Implementation map

| Area | File |
|---|---|
| Tailwind config | `tailwind.config.js` |
| Component CSS | `resources/css/app.css` |
| Client layout | `resources/views/components/layouts/app.blade.php` |
| Admin layout | `resources/views/components/layouts/admin.blade.php` |
| Dashboard | `resources/views/dashboard/index.blade.php` |
| Login | `resources/views/auth/login.blade.php` |
| Portal link tile | `resources/views/components/portal-link-brand.blade.php` |

---

## Change log

| Date | Change |
|---|---|
| 2026-06-16 | Sync marketing design system into `Brain/design/`; expand UIUX with portal mapping |
| 2026-06-16 | Align portal UI with onit.ltd (Barlow, dark shell, benefit cards) |
