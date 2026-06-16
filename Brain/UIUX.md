# On IT Portal — UI/UX Design

## Design Principles

- **Modern**: Gradient hero, mesh backgrounds, elevated service tiles — not generic boxed cards
- **Professional**: MSP-grade quality reflecting On IT's brand standards (orange + dark navy)
- **Responsive**: Mobile-first — hamburger nav, sign-in-first login, 44px touch targets, safe-area insets
- **Fast**: Lightweight rendering, no heavy JS frameworks
- **Accessible**: Semantic HTML, sufficient colour contrast, keyboard navigable

## Brand Palette

| Element | Value |
|---|---|
| Primary accent | `#FF7000` (On IT orange) |
| Primary hover | `#E86200` |
| Dark brand base | `#011926` (grey / blue / black) |
| Dark muted | `#18313D` |
| Background | `#F8FAFC` (Slate 50) |
| Card background | `#FFFFFF` |
| Success | `#16A34A` |
| Warning | `#D97706` |
| Danger | `#DC2626` |
| Logo | Placeholder SVG with "On IT" text |

## Typography

- Font: Inter (via Google Fonts / Bunny Fonts CDN)
- Headings: font-semibold, text-slate-900
- Body: text-slate-600, text-sm to text-base
- Labels: text-sm font-medium text-slate-700

## Layout Structure

### App Layout (Client)

```
┌──────────────────────────────────────────────┐
│  Header: Logo | Nav (Dashboard, Support) | User Menu  │
├──────────────────────────────────────────────┤
│                                              │
│  Main Content Area (max-w-7xl, centered)     │
│                                              │
├──────────────────────────────────────────────┤
│  Footer: © On IT | Privacy | Support         │
└──────────────────────────────────────────────┘
```

### Admin Layout

```
┌──────────┬───────────────────────────────────┐
│          │  Header: Page Title | User Menu   │
│  Sidebar ├───────────────────────────────────┤
│  Nav     │                                   │
│          │  Main Content Area                │
│          │                                   │
└──────────┴───────────────────────────────────┘
```

## Component Library

### Card (`<x-card>`)
White background, rounded-lg, shadow-sm, border border-slate-200, padding p-6.

### Service Card (`<x-service-card>`)
Uses `resolved_url`. SuperOps embedded shows "Open Support"; external shows "Launch".

### Support (`/support`)
Ticket table, detail view, new request form. On IT chrome — not SuperOps branding.

### Badge (`<x-badge>`)
Variants: `default`, `success`, `warning`, `danger`, `info`. Rounded-full, text-xs, px-2.5 py-0.5.

### Alert (`<x-alert>`)
Variants: `info`, `success`, `warning`, `danger`. With optional dismiss (Alpine.js).

### Empty State (`<x-empty-state>`)
Icon, title, description for when no data exists.

## Dashboard Wireframe

```
┌──────────────────────────────────────────────┐
│  Welcome back, John Smith                    │
│  Acme Corporation                            │
├──────────────────────────────────────────────┤
│  ┌─────────┐ ┌─────────┐ ┌─────────┐       │
│  │ Support │ │ Licensing│ │  M365   │       │
│  │ Portal  │ │ Portal  │ │         │       │
│  └─────────┘ └─────────┘ └─────────┘       │
│  ┌─────────┐ ┌─────────┐                    │
│  │Knowledge│ │ Billing │                    │
│  │  Base   │ │ Portal  │                    │
│  └─────────┘ └─────────┘                    │
├──────────────────────────────────────────────┤
│  Recent Notices          │ Quick Links       │
│  ┌──────────────────┐   │                   │
│  │ Notice 1         │   │                   │
│  │ Notice 2         │   │                   │
│  └──────────────────┘   │                   │
├──────────────────────────────────────────────┤
│  Recommendations         │ Opportunities     │
│  ┌──────────────────┐   │ ┌──────────────┐  │
│  │ Rec 1  [High]    │   │ │ Opp 1 [Open] │  │
│  │ Rec 2  [Medium]  │   │ │ Opp 2 [Open] │  │
│  └──────────────────┘   │ └──────────────┘  │
└──────────────────────────────────────────────┘
```

## Responsive Breakpoints

| Breakpoint | Width | Layout |
|---|---|---|
| Mobile | < 640px | Single column, stacked cards |
| Tablet | 640–1024px | 2-column service cards |
| Desktop | > 1024px | 3-column service cards, sidebar layout |

## Login Page

Centred card on dark On IT gradient background. Orange On IT mark at top. "Sign in with Microsoft" button uses the dark brand base with Microsoft logo. Brief description text below.

## Admin Tables

- Striped rows with hover highlight
- Action buttons: Edit (orange), Delete (red with confirmation)
- Status badges for active/inactive
- Pagination at bottom (15 per page)

## Interaction Patterns

- Alpine.js for: dropdown menus, alert dismiss, mobile sidebar toggle, delete confirmation modals
- Flash messages displayed as dismissible alerts at top of content area
- Form validation errors displayed inline below fields in red
