# On IT Design System (canonical copy)

Synced from the marketing website Claude Code skill folder:

`On IT Updated - Documents/Marketing/Website/Claude Code/`

When marketing updates the design system, copy these three files into `Brain/design/` and update [UIUX.md](../UIUX.md) if portal implementation changes.

## Files

| File | Purpose |
|---|---|
| [SKILL.md](SKILL.md) | Brand rules, page structure, animations, non-negotiables |
| [design-system.md](design-system.md) | Colour tokens, typography, heading stack, spacing, badges |
| [components.md](components.md) | Hero, benefit cards, agenda, SLA table, FAQ, CTAs, forms |

## Portal vs marketing

| Marketing site | Portal (`app.onit.ltd`) |
|---|---|
| Standalone HTML + Tailwind CDN | Laravel 11 + Blade + Vite + Tailwind |
| No nav/footer (GHL embed) | Client header + admin sidebar |
| Hero with bg image, wipe animations | Dashboard heading stack, no hero image |
| Lucide icons | SVG logos for portal links where needed |
| `max-w-5xl` sections | `max-w-portal` (64rem) |

Portal-specific implementation is documented in [UIUX.md](../UIUX.md).
