# On IT Portal Design System

Aligned with the **On IT website design system** (`onit.ltd` marketing skill). Source of truth for portal UI.

**External references (marketing site):**
- `SKILL.md` - brand rules, non-negotiables
- `design-system.md` - colours, typography, spacing
- `components.md` - cards, CTAs, heading stack, orange rule

---

## Brand rules (portal)

1. **Background** `#011926` with grid overlay and subtle orange radial glow (`.onit-bg` + `.grid-overlay`)
2. **Accent** `#FF7000` for CTAs, rules, hover, labels. Not body paragraph text.
3. **Fonts** Barlow Condensed (headings, buttons, labels) + Barlow (body)
4. **Heading stack** white block then orange block, left-aligned, preceded by orange rule
5. **Cards** solid `#071f2e`, border `#0f3048`, square corners, orange top line on hover
6. **Buttons** square corners only. `.cta-btn` (primary) and `.cta-btn-ghost` (secondary)
7. **No** rounded cards, pill buttons, glass surfaces, em dashes, or light grey SaaS backgrounds
8. **Body text** `text-white/80` and `text-white/60` on dark surfaces

---

## Colour tokens

| Token | Hex | Usage |
|---|---|---|
| `onit` | `#FF7000` | Accent, CTAs, rules |
| `onit-hover` | `#e56300` | Button hover |
| `onit-ink` | `#011926` | Shell, header |
| `onit-surface` | `#071f2e` | Cards |
| `onit-surface-hover` | `#0a2a3f` | Card hover |
| `onit-border` | `#0f3048` | Card and panel borders |

---

## Typography

| Element | Font | Style |
|---|---|---|
| Section headings | Barlow Condensed 800 | Uppercase, heading stack |
| Card titles / labels | Barlow Condensed 700 | Uppercase |
| Body | Barlow 300 | `text-white/80` |
| Muted | Barlow 300 | `text-white/60` |
| Nav links | Barlow Condensed 600 | Uppercase |

---

## CSS classes (`resources/css/app.css`)

| Class | Purpose |
|---|---|
| `.onit-bg` / `.grid-overlay` | Global dark background |
| `.orange-rule` | 40x3px rule above headings |
| `.heading-stack` | White + orange heading blocks |
| `.section-heading-white` / `.section-heading-orange` | Heading stack lines |
| `.benefit-card` | Portal link cards, panels, admin cards |
| `.portal-link` | Clickable portal tile |
| `.cta-btn` / `.cta-btn-ghost` | Primary and secondary actions |
| `.portal-body` / `.portal-body-muted` | Body copy on dark |
| `.portal-label` | Orange uppercase label |
| `.admin-table-wrap` | Admin data tables |

---

## Layout

- Max width: `max-w-portal` (64rem / 1024px)
- Client shell: dark header, dark main, grid background
- Admin: same dark shell, sidebar `bg-onit-ink`

---

## Anti-patterns (still apply)

No pill badges with dots, arrow CTAs, em dashes, generic Inter/DM Sans, light mode dashboard, rounded-xl cards, or transparent glass cards.

Admin table status badges (`<x-badge>`) are allowed for data only.

---

## Change log

| Date | Change |
|---|---|
| 2026-06-16 | Align portal with onit.ltd marketing design system (Barlow, dark shell, benefit cards) |
