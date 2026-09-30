# Client portal UI overhaul

**Status (2026-09-08):** Client home/Reports copy and glance chrome aligned with the rest of the portal (Barlow, square cards, heading stack). Quiet “Something look off?” strip instead of a Beta warning. Last month is hidden until snapshot history exists.

**Canonical mockup:** `Customer dashboard UI mockups.zip` → `Client Dashboard Mockups.dc.html`

| Mockup ID | Portal page | Notes |
|-----------|-------------|--------|
| **1a** Dark | `/dashboard` (`dashboard/glance.blade.php`) | Hero + traffic lights + value stats + services strip + 4 equal-height source cards + activity |
| **1b** Light | Not implemented (dark site palette preferred) | Same content model as 1a |
| **1c** Value report | `/reports` (`reports/index.blade.php`) | Navy services rail + light main + stacked rows + activity |

Organisation / Security / M365 routes stay as drill-downs from Dashboard. **Organisation nav is gone (2026-08-19).** Use **Services** → Security, Microsoft 365, Support & Devices.

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
| **Security** (Huntress) | open incidents = 0 and no unresponsive agents | any open incident, or any unresponsive agents | open incidents ≥ 3, or unresponsive ≥ 5 **and** ≥ 20% of agents |
| **Backup** (Dropsuite) | no failed/retrying last 24h | - | any failed/retrying in last 24h feed |

**SuperOps deliberately ignores device online/offline.** Many customers have kit that is offline by design (field engineers, plant, night power-off). Traffic lights here are about **SLA and ticket backlog**, not RMM presence. Device counts can still appear as informatics on the card.

### Product not live

| State | Tone | Label |
|-------|------|-------|
| setup_needed | yellow | Setup needed |
| cold | yellow | Issues |
| loading | yellow | Loading |
| platform / error | red | Critical |
| not_sold | grey | **Add-on** (Huntress/Dropsuite) or **Not on plan** (other) |

Hero uses the **worst** live/setup signal: critical → “N services need critical attention”; issues → “N services have issues to review”; else “All systems protected.”

## Live vs Not set up

Numbers only from existing feeds. **Never invent MTD.** Prefer omit optional posture fields (Secure Score, MFA) when Graph returns 403 / empty over walls of “Not set up”.

### Shipped enrichment (2026-08-10 customer gap close)

| Field | Source | Notes |
|-------|--------|--------|
| **Waiting on you** | SuperOps open ticket statuses `Waiting on Client` / `Waiting on Customer` | Cache `superops-dashboard:v5` |
| **Threats stopped (MTD)** | Huntress incident list closed this calendar month (London) | Value strip **only when Huntress is sold/live** - never empty “-” when not sold |
| **Threat responses (MTD)** | Remediation actions on cases touched this month | ITDR-ish narrative only when &gt; 0 |
| **Activity feed** | SuperOps open + recently closed tickets, Huntress cases (actions nested), Dropsuite mailbox errors | One clickable ticket or case. Public replies are on the ticket page. Internal notes are not shown. See below. |
| **Last month compare** | `client_metric_daily_snapshots` + `portal:capture-metric-snapshots` @ 02:15 | Until a prior-month row exists, **hide** the Last month control (do not show a locked toggle). |
| **Secure Score / MFA %** | Graph `security/secureScores` + `reports/authenticationMethods/userRegistrationDetails` on M365 insights refresh (`m365-insights:v4`) | Soft-fail if permission missing; licence refresh still succeeds |
| **Avg first response** | - | **Not shipped** - SuperOps query still uses resolution SLA only (adding unproven GraphQL fields risks bad refresh) |
| **Device patch / need updates** | SuperOps `patchStatus` on asset list (Support & Devices) | Restart uses 2+ day `sysUptime` with paginated names; RAM/disk not shown |
| **Restore retention days** | - | **Not shipped** - not in Dropsuite summary payload |

### Not sold vs unprotected (client-facing)

For **Huntress** / **Dropsuite** when entitlement is not sold:

- Status label: **Add-on** (not “Not sold”).
- Card reason: optional feed; **On IT still helps via support tickets** - tile only tracks the automated product feed when on plan.
- **Value strip / report hero stats:** if MDR not sold, show **tickets resolved · open tickets · SLA** - do **not** lead with Threats stopped `-` (reads as zero protection).

Support (SuperOps) remains the protection signal for support-only orgs.

### Activity feed ("What we have done for you")

Glance and Reports share `ClientActivityFeedService` + `ClientActivityCopy`.

**Layout:** a vertical timeline, newest first. Each point is one ticket or case: subject, the action (status sentence), and the status badge. Security fixes are not extra points on the line.

**Show detail** opens that action on the same page. Support loads the public replies (`GET /support/{ticketId}/actions`: logged, you replied, a technician replied). A security case shows its summary and each fix. Internal notes are not loaded. "Open the full record" is only inside that panel.

The home list still labels the action from status, because the dashboard cache does not store the thread. `ticketId` is stored from the next SuperOps refresh (`superops-dashboard:v5`). Until that refresh, Show detail cannot load the replies.

**Support copy from SuperOps status only** (dashboard cache has subject/status/times, not conversations):

| SuperOps status | Headline | Badge |
|---|---|---|
| Waiting on Client / Customer | We sent an update and are waiting on you | Waiting on you |
| In Progress | A technician is working on this | In progress |
| Open | This is in our support queue | Open |
| On Hold | We have paused this ticket | On hold |
| Pending | We are lining up the next step | Pending |
| Waiting on Vendor | We are waiting on a supplier | Waiting on supplier |
| Reopened | We have reopened this ticket | Reopened |
| Closed / Resolved | We closed this ticket | Closed |
| Closed (no response) | We closed this after no reply | Closed |

Also in the mapper: scheduled, waiting for update, escalated, cancelled. Closed tickets are included so the list is not only open work.

**Not on the home list:** internal notes, or a per-reply timestamp. Those replies are on the ticket page. Internal notes (`getTicketNoteList`) are never loaded.

**Security:** one row per case, linking to the case page. Remediations (isolate / credentials / contain, unapproved vs completed) are actions under that case.

**Backup:** failed/retrying mailbox attention.

Times prefer SuperOps `updatedTime`, then resolution, then created. Cache key stays `superops-dashboard:v5`; refresh to pick up `updatedTime`.

### Product mix → different client homes (source of truth)

| Huntress sold? | Value strip (hero) | Security tile | Technician notes |
|----------------|--------------------|---------------------------|------------------|
| **No** | Tickets resolved · Open tickets · SLA met | **Add-on** + support-still-helps copy | **Support-led home** - grey H chip is expected, not a fault |
| **Yes**, feed live | Threats stopped · Tickets resolved · SLA | Traffic lights from incidents/agents | MDR metrics are real Huntress feed only |
| **Yes**, not mapped / cold | Same triple; threats may be `-` until live | Setup needed / loading | Finish mapping + IH; do **not** invent counts from tickets |

**Code:** `ClientHomeOverviewService::staffHomeComposition()` + glance/Reports value strip rules.  
**Staff UI:** Admin → **Clients → Edit** → panel **What the client sees at home** (`_client-home-composition.blade.php`). Clients list legend + Admin Dashboard sold-coverage blurb link here.  
**No in-app “view as client”** - use Integration Health or a real client login for live numbers.

Technicians: if a customer asks “why no threats stopped?”, check Huntress sold. Grey H = by design. Do not treat support-led homes as broken MDR.

## Mobile (2026-08-18)

Client portal pages use a **mobile-first shell** (not Staff Admin - that already had a drawer).

| Area | Behaviour |
|------|-----------|
| **Bottom tab bar** | Phone only: Home, Support, Reports (or Security/M365 fallback), **More** |
| **More menu** | Slide-up sheet with full nav including **Services** (Security, M365, Support & Devices), Staff Admin |
| **Header** | Sticky; compact height on small screens; **Services** dropdown on desktop |
| **Glance** | Status pills + services strip horizontal scroll; value/column grids single column; activity timestamps stack |
| **Support & Devices** | Open/closed tickets → card list on phone; Refresh CTA full width |
| **Support** (legacy ticket list) | Ticket table → tap cards on phone |
| **Reports** | Existing stack layout + extra bottom padding for tab bar |

CSS: `resources/css/app.css` (`.portal-bottom-nav`, `.portal-ticket-card`, `.portal-scroll-strip`). Component: `resources/views/components/portal-bottom-nav.blade.php`.

Still optional later: PWA install, offline shell.

## Reports layout (critical)

Do **not** use Tailwind `flex` / `lg:flex-row` / `w-full` for the 1c rail+main split on production. Purged CSS left `display:flex` (row) + full-width rail, which pushed the light main panel into a thin strip off the right edge.

**Fix:** pure CSS Grid (`.rp-shell { grid-template-columns: 280px minmax(0,1fr) }`) with layout in a scoped `<style>` block inside the view.

## Files

| Path | Role |
|------|------|
| `app/Services/Portal/ClientHomeOverviewService.php` | Column/metric assembly; **`staffHomeComposition()`** for technicians |
| `app/Services/Portal/ClientActivityFeedService.php` | Composed “what we’ve done” list |
| `app/Services/Portal/ClientActivityCopy.php` | Status → client action headline + badge |
| `app/Services/Portal/ClientMetricSnapshotService.php` | Daily MoM snapshot store/compare |
| `app/Console/Commands/CaptureClientMetricSnapshotsCommand.php` | `portal:capture-metric-snapshots` |
| `resources/views/dashboard/glance.blade.php` | 1a home |
| `resources/views/dashboard/partials/_glance-column.blade.php` | Source card |
| `resources/views/reports/index.blade.php` | 1c report (CSS Grid, not Tailwind flex) |
| `resources/views/admin/clients/_client-home-composition.blade.php` | Staff “what client sees” panel |
| `ClientReportsController` | `/reports` |

## Changelog

| Date | Note |
|------|------|
| 2026-09-08 | Activity feed: action headlines + high-contrast status badge; include closed tickets; SuperOps `updatedTime` on dashboard ticket rows. |
| 2026-09-08 | Filter chips (M365 people/groups, Huntress All/Active/Resolved) hide rows with CSS - no full page reload / no Alpine x-show on every row. |
| 2026-08-24 | **Contact Support** hub in nav (client users only): ticket + phone/hours + new starter form → SuperOps API. |
| 2026-08-24 | Client portal Beta banner (superseded 2026-09-08 by quiet feedback strip). |
| 2026-08-24 | M365 directory: Download Excel + CSV (licences section then users with licences). |
| 2026-08-19 | Dashboard Huntress card title is **Security** (was Detection & Response). |
| 2026-08-18 | **M365 directory:** friendly licence chips; name column drops SuperOps `(User Mailbox)` suffix (Type column already has it). |
| 2026-08-18 | **Mobile overhaul:** bottom tab nav, slide-up More menu, glance scroll strips + single-column grids, support/org ticket cards on phone. |
| 2026-08-11 | **Last month locked UX:** padlock segment + always-visible hint under toggle (glance + Reports); no finicky hover tooltip. |
| 2026-08-11 | **Last month hover (superseded):** client-facing title tooltip - replaced by always-visible hint. |
| 2026-08-11 | **Reports 500:** Blade rejected nested `month@if` (open tag not compiled); last-month optional date uses separate `@if` lines. |
| 2026-08-10 | **Staff composition panel:** Edit Client shows support-led vs MDR home from sold products; Clients list + Admin coverage copy. |
| 2026-08-10 | **Not-sold reframed:** Huntress/Dropsuite “Add-on” + support-still-helps copy; value strip omits empty Threats stopped when MDR not sold. |
| 2026-08-10 | **Customer gap close:** waiting-on-client; Huntress threats/responses MTD; activity feed; nightly metric snapshots + last-month deltas; Graph Secure Score/MFA (soft-fail). Still open: avg first response, patch posture, restore retention days. |
| 2026-08-10 | **Security posture Graph:** `SecurityEvents.Read.All` + `AuditLog.Read.All` + `Reports.Read.All` on OnIT Portal for Portals; existing tenants re-Accept only - [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md). |
| 2026-08-10 | Initial glance scaffold (wrong visual language). |
| 2026-08-10 | **Rewrite** to match zip 1a/1c; explicit gaps kept. |
| 2026-08-10 | **Polish:** reports layout inline-safe; glance compact “not set up”; quieter activity. |
| 2026-08-10 | **Reports layout:** CSS Grid (broken Tailwind flex purging). Home: pipeline rows removed; quieter activity; Pax8 logo white/`8` orange on dark. |
| 2026-08-10 | **Traffic lights:** explicit Healthy / Issues / Critical bands per SuperOps, M365, Huntress, Dropsuite; hero takes worst. |
| 2026-08-10 | **Why this colour:** `status_reason` plain English for client admins on each service card + hero detail. |
| 2026-08-10 | **SuperOps lights rework:** ignore offline devices; SLA + open-ticket backlog only. |
| 2026-08-10 | **Reports width/mobile:** full content width (no 1280 cap); rail no secondary On IT logo; stack + larger tap targets on small screens. |
