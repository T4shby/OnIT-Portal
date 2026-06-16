# On IT Portal Design System

Single source of truth for UI decisions. When in doubt, choose the simpler, flatter option.

---

## Design principles

1. **Plain over polished** - Looks like a real MSP built it, not a template.
2. **Flat over flashy** - Solid colours and borders. No glows, meshes, or gradients.
3. **Text over decoration** - Say things with words, not badges, dots, or arrow CTAs.
4. **Functional over ornamental** - Every element should do a job.
5. **Responsive** - Mobile-first, 44px touch targets, safe-area insets.
6. **Accessible** - Semantic HTML, contrast, keyboard navigation.
7. **No em dashes** - Never use em dashes in user-facing copy. Use a full stop, comma, colon, or hyphen (`-`) instead.

---

## Never use (anti-patterns)

These read as generic AI / SaaS template output. **Do not add them.**

| Pattern | Example | Use instead |
|---|---|---|
| Radial / mesh / ombre glows | Orange blob in hero corner | Flat `bg-onit-ink` with optional `border-l-4 border-onit` |
| Gradient backgrounds | `bg-gradient-to-*` on hero or cards | Solid brand colours |
| Gradient top borders on cards | Orange-to-navy stripe on tile | `border border-slate-200`, `hover:border-onit` |
| Pill badges with status dots | `● On IT Technology Partners` in hero | Plain text line under the name |
| Uppercase micro-labels | `WELCOME BACK`, `SERVICES`, `CLIENT HUB` | Sentence-case headings or no label at all |
| Corner link CTAs with arrows | `Open portal →` on service cards | Whole card is the link; title turns orange on hover |
| Filled nav pill buttons | Orange `Dashboard` button in header | Text nav links, orange on hover/active |
| Marketing taglines | "Your IT hub. One sign-in." | Factual copy only |
| Decorative shadows on brand marks | `shadow-onit/25` on logo boxes | Flat `bg-onit` square |
| Comment-prefix styling | `// Acme Corp` | Plain secondary text |
| Em dashes in copy | Long dash between clauses in a sentence | Full stop, comma, colon, or hyphen (`-`) |

**Admin exception:** Small status labels in data tables (Active / Inactive, priority) are fine. They are data, not decoration. Keep them minimal (`text-xs`, no dots or glows).

**Empty table cells:** Use `-`, not an em dash.

---

## Brand palette

| Token | Hex | Usage |
|---|---|---|
| `onit` | `#FF7000` | Accent, hover states, active nav, left borders |
| `onit-hover` | `#E86200` | Button / link hover |
| `onit-light` | `#FFF2E8` | Icon background on tile hover |
| `onit-ink` | `#011926` | Header, hero, login panel |
| `onit-muted` | `#18313D` | Secondary dark surfaces (admin) |
| Background | `#F1F5F9` (`slate-100`) | Page shell |
| Card | `#FFFFFF` | Tiles, cards, help block |
| Body text | `slate-500` / `slate-600` | Descriptions, footer |
| Headings | `onit-ink` | Section titles on light backgrounds |

---

## Typography

- **Font:** DM Sans (Bunny Fonts CDN). Clean sans-serif for client-facing UI.
- **Headings:** `font-semibold`, no uppercase tracking unless it is a table column header.
- **Body:** `text-sm` to `text-base`, `text-slate-500` or `text-slate-600`.
- **Nav:** `text-sm`, muted grey default, `text-onit` on hover and active.

---

## Layout

### Client (`components/layouts/app.blade.php`)

```
┌─────────────────────────────────────────────────────┐
│  [IT] On IT Portal     Dashboard  Admin     [P]     │  dark header, orange bottom border
├─────────────────────────────────────────────────────┤
│  Hero: name + client (flat navy, orange left bar)   │
│  Portal tiles (white, bordered)                     │
│  Help block (white, bordered)                       │
├─────────────────────────────────────────────────────┤
│  © On IT …                    Simplicity & Value    │  white footer
└─────────────────────────────────────────────────────┘
```

- Max width: `max-w-6xl`
- Header: `bg-onit-ink`, `border-b-2 border-onit`
- Main: `bg-slate-100` via `.portal-shell`

### Admin (`components/layouts/admin.blade.php`)

- Same font and colours.
- Sidebar: text links, orange on hover/active (no filled orange pills).
- Content: white cards on slate background.

---

## CSS component classes (`resources/css/app.css`)

| Class | Purpose |
|---|---|
| `.portal-shell` | Page wrapper, min-height, slate background |
| `.portal-header` | Dark header with orange bottom border |
| `.portal-nav-link` | Text nav, grey default, orange on hover |
| `.portal-nav-link-active` | Orange text for current page |
| `.portal-hero` | Flat navy block, orange left border, user name |
| `.portal-tile` | Clickable service card, border hover to orange |
| `.portal-tile-icon` | Icon box in tile |
| `.portal-btn` | Pax8-style pill, `rounded-full border-2` |
| `.portal-btn-primary` | Orange fill |
| `.portal-btn-secondary` | White fill, grey border |
| `.portal-btn-dark` | Transparent on dark backgrounds |
| `.login-panel` / `.login-card` | Sign-in split layout |

---

## Components

### Service card (`<x-service-card>`)

- Entire card is an `<a>`. No separate "Open portal →" link.
- Icon + title + optional description.
- Title turns orange on hover; border turns orange on hover.

### Card (`<x-card>`)

- White, `border border-slate-200`, padding. No shadow unless needed for elevation in admin tables.

### Portal logo (`<x-portal-logo>`)

- Flat orange square with "IT" text. No gradient, no shadow.

### Buttons

- **Pill shape** (`rounded-full`, `border-2`) for explicit actions only: sign in, external links.
- **Not** for navigation. Nav is always text links.

### Badge (`<x-badge>`)

- **Admin tables only:** Active/Inactive, priority, status.
- Never on the client dashboard or hero.

---

## Pages

### Dashboard

```
┌─ portal-hero ─────────────────────┐
│  John Smith                       │
│  Acme Corporation                 │  plain text, no badges
└───────────────────────────────────┘

Your portals                    2 available
┌──────────────┐  ┌──────────────┐
│ [icon]       │  │ [icon]       │
│ SuperOps     │  │ Pax8         │  whole card links out
│ description  │  │ description  │
└──────────────┘  └──────────────┘

┌─ Need help? ────────── [Visit onit.ltd] ─┐
└──────────────────────────────────────────┘
```

### Login

- Mobile: sign-in card first.
- Desktop: marketing column (logo + title + one line) | sign-in card.
- Microsoft button uses `.portal-btn-secondary` with MS logo.
- No gradients on the dark panel.

---

## Responsive

| Breakpoint | Layout |
|---|---|
| `< 640px` | Single column, hamburger nav, stacked tiles |
| `640px to 1024px` | 2-column portal tiles |
| `> 1024px` | 2-column tiles, horizontal nav |

Touch targets: minimum 2.75rem (`.touch-target`).

---

## Interaction

- Alpine.js: mobile menu, user dropdown, alert dismiss, admin modals.
- Flash messages: dismissible alerts at top of main content.
- Form errors: inline below fields in red.

---

## Reference

- Brand colours confirmed with On IT (`#FF7000`, `#011926`).
- Button shape inspired by Pax8 (pill + border), not their colour scheme.
- Font: DM Sans. Readable sans-serif, not monospace.
