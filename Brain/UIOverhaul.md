# Client portal UI overhaul (`UIOverhaul` branch)

**Status (2026-08-10):** In progress on branch **`UIOverhaul`** (keep off `main` until accepted).  
Mockup source: local *Client Dashboard Mockups* HTML (directions 1a home · 1c value report).

## Intent

| Route | Audience | Purpose |
|-------|----------|---------|
| `/dashboard` | Client Admin (and requesters with systems) | **Primary home** — “Your IT at a glance” + four service columns; portals + support remain |
| `/reports` | Org-wide only (`view-organisation-wide`) | Monthly **service review** layout (value report mockup 1c) |
| `/client-admin` | Unchanged | Deep Organisation / My Systems metrics (tiles, tickets table, refresh) |
| Security · M365 | Unchanged | Drill-downs from home columns |

Dashboard and Organisation are **related, not deleted**: home is the narrative glance; Organisation remains the operational detail page.

## Architecture

Service: `App\Services\Portal\ClientHomeOverviewService`  
Builds presentation columns from existing feeds (`DashboardFeedRegistry` + `ClientProductService`), **never invents numbers**.

### Column states

| State | Meaning |
|-------|---------|
| `live` | Snapshot present; metrics shown |
| `loading` | Refresh in flight |
| `not_sold` | Entitlement off |
| `setup_needed` | Sold but map/tenant incomplete |
| `platform` | MSP-side credentials/`*_ENABLED` missing |
| `cold` | Linked but no snapshot |
| `pipeline` (metrics) | Sold/live product can work, but **this metric** needs future work |

### Explicit pipeline gaps (do not “skip” quietly)

Marked **Not set up** in UI with why:

| Gap | Needed later |
|-----|----------------|
| Month vs last month comparisons | Daily/period snapshot store |
| Avg first response (SuperOps) | Ticket response-time aggregation |
| Secure Score | Graph Secure Score + consent |
| MFA coverage / users without MFA | Graph auth methods/registration reports + consent |
| ITDR narrative outcomes | Huntress ITDR event mapping |
| Restore points kept (Dropsuite) | Extra API field |
| “What we’ve done for you” activity | Cross-product event pipeline |
| Incidents this month vs open/resolved snapshot | Period-scoped Huntress query |

## Layout files

| File | Role |
|------|------|
| `resources/views/dashboard/glance.blade.php` | New client home |
| `resources/views/dashboard/partials/_service-column.blade.php` | Column card |
| `resources/views/reports/index.blade.php` | Reports page |
| `resources/views/dashboard/index.blade.php` | Legacy simple home (staff / no client) |
| Controllers | `DashboardController`, `ClientReportsController` |

## Branch / release

1. Develop only on **`UIOverhaul`**.  
2. When accepted: merge to `main` → Plesk deploy as usual.  
3. Do **not** deploy mid-experiment without review.

## Changelog

| Date | Note |
|------|------|
| 2026-08-10 | Branch + home glance UI + Reports scaffold + pipeline/setup callouts; Organisation kept. |
