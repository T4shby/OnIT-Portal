# Client portal UI overhaul (`UIOverhaul` branch)

**Status (2026-08-10):** In progress on branch **`UIOverhaul`**.  
**Canonical mockup:** `Customer dashboard UI mockups.zip` → `Client Dashboard Mockups.dc.html`

| Mockup ID | Portal page | Notes |
|-----------|-------------|--------|
| **1a** Dark | `/dashboard` (`dashboard/glance.blade.php`) | Hero + traffic lights + value stats + services strip + 4 source columns + activity |
| **1b** Light | Not implemented (dark site palette preferred) | Same content model as 1a |
| **1c** Value report | `/reports` (`reports/index.blade.php`) | Navy services rail + light main + stacked rows + activity |

Organisation / Security / M365 routes stay as drill-downs (“Details →”).

## First pass mistake

An early `UIOverhaul` commit reused the marketing heading-stack home (Logged in / Your portals). That is **not** the customer mockup. Rewritten against zip **1a / 1c** (Poppins, `#0a2537` cards, traffic lights, value strip, report rail).

## Traffic lights (Healthy / Issues / Critical)

Dots and pill labels are driven by `tone` + `status_label` on each column in `ClientHomeOverviewService`.

Pill label is **Healthy / Issues / Critical**. Each column also exposes **`status_reason`**: one plain-English sentence for client admins (why this colour). Shown on cards, top pills (title tooltip), hero detail, and reports rows. No feed product names, mapping IDs, or “snapshot” jargon in that reason.

### Live service rules

| Service | Healthy | Issues | Critical |
|---------|---------|--------|----------|
| **Support & Devices** (SuperOps) | SLA ≥ 95% (or no SLA sample) and open tickets &lt; 10 | SLA &lt; 95%, or open tickets ≥ 10 | SLA &lt; 90%, or open tickets ≥ 25 |
| **Microsoft 365** | not over-assigned | seats assigned &gt; purchased | assigned &gt; 110% of purchased |
| **Detection & Response** (Huntress) | open incidents = 0 and no unresponsive agents | any open incident, or any unresponsive agents | open incidents ≥ 3, or unresponsive ≥ 5 **and** ≥ 20% of agents |
| **Backup** (Dropsuite) | no failed/retrying last 24h | — | any failed/retrying in last 24h feed |

**SuperOps deliberately ignores device online/offline.** Many customers have kit that is offline by design (field engineers, plant, night power-off). Traffic lights here are about **SLA and ticket backlog**, not RMM presence. Device counts can still appear as informatics on the card.

### Product not live

| State | Tone | Label |
|-------|------|-------|
| setup_needed | yellow | Setup needed |
| cold | yellow | Issues |
| loading | yellow | Loading |
| platform / error | red | Critical |
| not_sold | grey | Not sold |

Hero uses the **worst** live/setup signal: critical → “N services need critical attention”; issues → “N services have issues to review”; else “All systems protected.”

## Live vs Not set up

Numbers only from existing feeds. **Never invent MTD.** Prefer omit optional posture fields (Secure Score, MFA) when Graph returns 403 / empty over walls of “Not set up”.

### Shipped enrichment (2026-08-10 customer gap close)

| Field | Source | Notes |
|-------|--------|--------|
| **Waiting on you** | SuperOps open ticket statuses `Waiting on Client` / `Waiting on Customer` | Cache `superops-dashboard:v3` |
| **Threats stopped (MTD)** | Huntress incident list closed this calendar month (London) | Value strip prefers this over lifetime remediated |
| **Threat responses (MTD)** | Remediation actions on cases touched this month | ITDR-ish narrative only when &gt; 0 |
| **Activity feed** | Compose SuperOps open tickets + Huntress cases/remediations + Dropsuite mailbox errors | Not a full event bus |
| **Last month compare** | `client_metric_daily_snapshots` + `portal:capture-metric-snapshots` @ 02:15 | Until a prior-month row exists, toggle stays disabled with honest message |
| **Secure Score / MFA %** | Graph `security/secureScores` + `reports/authenticationMethods/userRegistrationDetails` on M365 insights refresh (`m365-insights:v4`) | Soft-fail if permission missing; licence refresh still succeeds |
| **Avg first response** | — | **Not shipped** — SuperOps query still uses resolution SLA only (adding unproven GraphQL fields risks bad refresh) |
| **Device patch / need updates** | — | **Not shipped** — SuperOps asset payload has no patch posture fields yet |
| **Restore retention days** | — | **Not shipped** — not in Dropsuite summary payload |

## Reports layout (critical)

Do **not** use Tailwind `flex` / `lg:flex-row` / `w-full` for the 1c rail+main split on production. Purged CSS left `display:flex` (row) + full-width rail, which pushed the light main panel into a thin strip off the right edge.

**Fix:** pure CSS Grid (`.rp-shell { grid-template-columns: 280px minmax(0,1fr) }`) with layout in a scoped `<style>` block inside the view.

## Files

| Path | Role |
|------|------|
| `app/Services/Portal/ClientHomeOverviewService.php` | Column/metric assembly (live metrics only on home) |
| `app/Services/Portal/ClientActivityFeedService.php` | Composed “what we’ve done” list |
| `app/Services/Portal/ClientMetricSnapshotService.php` | Daily MoM snapshot store/compare |
| `app/Console/Commands/CaptureClientMetricSnapshotsCommand.php` | `portal:capture-metric-snapshots` |
| `resources/views/dashboard/glance.blade.php` | 1a home |
| `resources/views/dashboard/partials/_glance-column.blade.php` | Source card |
| `resources/views/reports/index.blade.php` | 1c report (CSS Grid, not Tailwind flex) |
| `ClientReportsController` | `/reports` |

## Changelog

| Date | Note |
|------|------|
| 2026-08-10 | **Customer gap close:** waiting-on-client; Huntress threats/responses MTD; activity feed; nightly metric snapshots + last-month deltas; Graph Secure Score/MFA (soft-fail). Still open: avg first response, patch posture, restore retention days. |
| 2026-08-10 | **Security posture Graph:** `SecurityEvents.Read.All` + `AuditLog.Read.All` + `Reports.Read.All` on OnIT Portal for Portals; existing tenants re-Accept only — [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md). |
| 2026-08-10 | Initial glance scaffold (wrong visual language). |
| 2026-08-10 | **Rewrite** to match zip 1a/1c; explicit gaps kept. |
| 2026-08-10 | **Polish:** reports layout inline-safe; glance compact “not set up”; quieter activity. |
| 2026-08-10 | **Reports layout:** CSS Grid (broken Tailwind flex purging). Home: pipeline rows removed; quieter activity; Pax8 logo white/`8` orange on dark. |
| 2026-08-10 | **Traffic lights:** explicit Healthy / Issues / Critical bands per SuperOps, M365, Huntress, Dropsuite; hero takes worst. |
| 2026-08-10 | **Why this colour:** `status_reason` plain English for client admins on each service card + hero detail. |
| 2026-08-10 | **SuperOps lights rework:** ignore offline devices; SLA + open-ticket backlog only. |
| 2026-08-10 | **Reports width/mobile:** full content width (no 1280 cap); rail no secondary On IT logo; stack + larger tap targets on small screens. |
