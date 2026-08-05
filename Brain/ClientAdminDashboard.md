# Client Admin dashboard

Client Admins (`client_admin`) see an organisation overview at `/client-admin` scoped to their own client. The page answers:

1. **Are my systems healthy?** — modular **dashboard feeds** (SuperOps devices, Huntress, Dropsuite, M365 licences)
2. **Are my issues being dealt with?** — open tickets (with priority breakdown and table), SLA %, ticket activity
3. **What value am I getting from On IT?** — licence utilisation and coverage metrics

## Dashboard feed contract (modular)

All organisation overview integrations implement `App\Contracts\DashboardFeed` and are registered in `AppServiceProvider` → `DashboardFeedRegistry`.

| Concern | Where |
|---------|--------|
| Contract | `app/Contracts/DashboardFeed.php` |
| Registry | `app/Services/Portal/DashboardFeedRegistry.php` |
| Feed adapters | `app/Services/Portal/Feeds/*DashboardFeed.php` |
| Metrics engines | `app/Services/{SuperOps,Huntress,Dropsuite,M365}/…` |
| System health tiles | `resources/views/client-admin/feeds/_*.blade.php` |
| Controller | injects **registry only** — no hard-coded vendor DI |
| Prewarm | loops critical vs optional feeds from registry |
| Orphan queue flags | `ClearsOrphanedFeedRefreshFlags` trait |

### Feed checklist (add a new vendor)

1. DB mapping column on `clients` + migration + Admin create/edit field  
2. `config/services.php` block + env (`*_ENABLED` if optional)  
3. `ApiClient` + `*MetricsService` with `summaryForClient` / `queueRefresh` / `needsBackgroundRefresh` / `refreshAndStore`  
4. Summary DTO with `hasData()`, `refreshInProgress`, `lastRefreshedAt`  
5. `Refresh*Job` on `high`, unique, write `*.refresh_started` / `*.last_result`  
6. Adapter implementing `DashboardFeed` + register in `AppServiceProvider` list (**order = tile order**)  
7. Blade partial under `client-admin/feeds/` if it is a System health tile; `overviewPartial(): null` for prewarm-only  
8. Integration Health: add to `FEED_COLUMNS` + `JOB_CLASS_HINT` + `clientRow()` builder (same adaptive `evaluate()` path as SuperOps)  
9. Unit tests + Brain section  

**Integration Health is the technician refresh dashboard** (`/admin/integration-health`): Huntress and Dropsuite are full columns next to SuperOps / M365 / Entra — same live poll, orphan clear, stuck/due/aging statuses, and adaptive prewarm.

**Priorities:**  
- `critical` — SuperOps (always prewarm even when queue deep)  
- `optional` — Huntress, Dropsuite, M365 (skipped when ≥40 jobs pending)

**Credentials:** one MSP partner API set per vendor in `.env` for all customers; per-client mapping IDs only.

---
## Roles

| Role | Code | Capabilities |
|------|------|--------------|
| Client Requester | `client_requester` | Standard portal, support, SSO launch |
| Client Billing Admin | `client_billing_admin` | Requester + billing capability (Pax8 UI not built yet) |
| Client Admin | `client_admin` | Billing + org overview, M365 directory, SuperOps summary |

Staff roles (`account_manager`, `super_admin`) are unchanged.

Entra sync creates new users as `client_requester` only. Sync updates never change `role`, so manually promoted `client_billing_admin` and `client_admin` users are preserved.

## Technician Integration Health (own tab)

**Who:** On IT `super_admin` / `account_manager` only — **Staff Admin → Integration Health** (`/admin/integration-health`).  
**Not** shown on Client Organisation overview / requester portals.

**Nav:** Own sidebar tab under Staff Admin (not buried on the dashboard). Dashboard shows a compact “Refresh pipeline” card linking to the tab. Portal top nav: **Organisation** (client metrics), **Microsoft 365**, **Staff Admin** (MSP).

Service: `App\Services\Admin\IntegrationHealthService`  
Controller: `App\Http\Controllers\Admin\IntegrationHealthController`

**Live UI:** Integration Health tab polls `GET /admin/integration-health/live` every **5 seconds** (pauses when the tab is hidden) and replaces the health table + pipeline cards. Layout is mobile-friendly (stacked client/job cards below `md`; table on desktop). Admin shell uses a **sticky** Staff Admin sidebar (`z-30`) so the main column never paints over the nav.

**Refresh timing:** Super Admin **Timing settings** (header) opens a **right-hand drawer** (`fixed` overlay `z-40`, body scroll-locked while open). Account managers can open the form but only Super Admins save. Quiet page title (no white plate heading). Status summary uses a left accent line, not a boxed plate. Cadence lives in DB `settings.freshness.*` only — **never** `.env`.

### Adaptive cadence (all clients)

Service: `App\Services\Portal\PortalFreshnessService`

| Mode | When | Default interval |
|------|------|------------------|
| Hot (`customer_activity`) | Any **client-portal** session (Client Admin / Billing / Requester) with recent activity | `freshness.hot_minutes` = **2.5** |
| Business hours idle | No customer sessions, inside work window | `freshness.work_idle_minutes` = **60** |
| Off-hours idle | No customer sessions, outside work window | `freshness.off_hours_idle_minutes` = **60** |

Also: presence window (`freshness.presence_minutes`, default 15), work start/end (`07:00`–`19:00` exclusive end), timezone (`Europe/London`).  
Derived: **requeue** ≈ `interval × 0.9` (min 0.5m); **soft window** for client-facing soft note ≈ `max(interval+1, interval×1.15)`.

Schedule (`routes/console.php`): every minute scheduler tick; when adaptive interval is **due** against last prewarm/Entra heartbeats, run `portal:prewarm-client-dashboards` and (if enabled) `portal:sync-entra-users`.

**Requires** `SESSION_DRIVER=database` for hot mode (customer presence counts DB sessions).

| Column | Meaning |
|--------|---------|
| SuperOps | Last dashboard cache; **due** / **aging** by adaptive requeue/soft window |
| M365 people / licences | Directory + insights caches; requeue adaptive |
| Entra sync | `clients.entra_synced_at` + SyncEntra job (same adaptive schedule cadence when enabled) |
| Huntress | Security cache when org linked; adaptive requeue |
| Dropsuite | Backup cache when org linked; adaptive requeue |
| Blockers / active | Queued/running/**stuck** (&gt;5m) |

Clients never see this page. Also shows queue depth (`jobs` high/default/failed) and oldest pending age.

### Pipeline visibility (technician)

When data looks “stuck”, the live panel answers **why** without SSH:

| Panel | What it shows |
|-------|----------------|
| Prewarm heartbeat | Last `portal:prewarm-client-dashboards` time, SuperOps/other jobs queued that run, queue-deep skip |
| Queue workers | pending / reserved / high / default / failed + worker-lag notice if jobs sit ≥90s with nothing reserved |
| Why isn’t it resetting? | Auto notices (orphaned flags cleared, due SuperOps, aging, stuck, last failure text) |
| Jobs table | Live `jobs` rows: class, client id, age seconds, waiting vs reserved, attempts |
| failed_jobs | Last failures with first error line |
| Status **due** | Feed past **adaptive requeue** age but under soft window — waiting prewarm/workers (not silent OK) |

Prewarm writes cache key `portal.prewarm.last_run` every run for the heartbeat.

**Soft-client banner caveat:** SuperOps Organisation page soft wording still uses `SUPEROPS_DASHBOARD_*` config minutes for client-facing “as of …” thresholds; technicians should trust Integration Health for true adaptive requeue.

**Entra sync visibility:** `SyncEntraClientJob::dispatchMarked()` sets `entra_sync.refresh_queued.{id}` **at dispatch time** (not only when the worker starts), so Active/Stuck shows **queued** immediately after Run Sync / Apply SCIM / artisan queue.

**Orphaned “queued”:** health status is not cache-only. If `refresh_queued` is set but there is **no matching row in `jobs`** and the job has not started, the flag is cleared on the next health read (unique-job discard, killed worker, stale cache). Queue pending = 0 with Active/Stuck “queued” was this bug.

Jobs record `*.refresh_started.{id}`, `*.last_result.{id}` (`duration_ms`, `success`, `error`).

### Parallelism (one client must not block all others)

PHP is not multi-threaded inside one worker. Parallelism = **multiple queue workers**:

- Production: **2** `queue:work --queue=high,default` processes (cron dual lines or Supervisor `numprocs=2`) — [Deployment.md](Deployment.md#10-configure-cron)
- Dashboard / M365 directory / insights jobs use **`high`**; Entra sync stays on **`default`**
- M365 directory no longer auto-queues on every stale page view (that re-set “in progress” forever); use prewarm / Refresh now

---

## SuperOps metrics

Service: `App\Services\SuperOps\SuperOpsClientMetricsService`

Cache key: `client:{client_id}:superops-dashboard:v2`

Dashboard payload includes: asset totals with online/offline split, open ticket count with priority breakdown, open-ticket table (top 10), resolution SLA % (30 days), and ticket logged/closed ranges.

| Setting | Source | Default |
|---------|--------|---------|
| Adaptive auto-refresh (hot / idle / hours) | **Integration Health UI** (`settings.freshness.*`) | 2.5m / 60m / 60m, 07:00–19:00 UK |
| Cache retention | `SUPEROPS_DASHBOARD_STALE_MINUTES` (env) | 10080 minutes (7 days) |
| Manual refresh cooldown | `SUPEROPS_DASHBOARD_REFRESH_COOLDOWN_SECONDS` (env) | 60 seconds |
| GraphQL page cap | `SUPEROPS_DASHBOARD_MAX_PAGES` (env) | 10 pages × 100 rows |

**Cadence (adaptive, all clients):** **not** in `.env`. Super Admin → **Integration Health → Auto-refresh timing**. Defaults are written to `settings` once via `portal:ensure-freshness-settings` or on first page load (`ensureDefaults()`); existing values are never overwritten.

**Copy — client vs technician**

| Audience | Surface | Tone |
|----------|---------|------|
| Client (`client_admin` etc.) | `/client-admin` | No “stale” / SuperOps jargon. Soft note: figures refresh often; timestamp as “Overview as of … UK”. Integration not linked → plain “not enabled yet”. |
| Technician (`super_admin` / `account_manager`) on same URL | `/client-admin` (when staff uses client switch) | Warning with age, requeue/target minutes, pointer to Integration Health. Footer shows last success + thresholds. |
| Technician only | Admin → Integration refresh health | Ages, `OK` / **AGING** (past target), queued/running/stuck, queue depths, process hints. |

Background refresh: `RefreshSuperOpsDashboardJob` on the **`high`** queue (before Entra/SCIM on `default`). Worker must run `--queue=high,default` — [Deployment.md](Deployment.md#11-run-the-queue-worker).

**Data should exist before anyone opens the page:**

1. Scheduler runs `portal:prewarm-client-dashboards` when the **adaptive interval is due** (hot when customers online, otherwise idle hour-scale defaults).
2. **SuperOps cold + due-for-refresh always queues** when last success age ≥ adaptive requeue minutes (`PortalFreshnessService::effectiveRequeueMinutes()`), even when the jobs table is deep. Clears orphaned `refresh_queued` when no matching `jobs` row.
3. M365 / Huntress / Dropsuite only when cold or past adaptive requeue age, and only when spare queue capacity (&lt; 40 pending).
4. Linking SuperOps Account ID (Save client) queues a cold prewarm if the cache is empty.
5. Page views **serve cache only** — they do not re-queue every time metrics are past the fresh window.
6. Queue workers: two minute-cron processes with `--max-time=55` (not 300) so workers do not stack; process `high` before `default` — [Deployment.md](Deployment.md#10-configure-cron).

The dashboard uses a wide (`96rem`) layout and compact responsive grids so desktop
and tablet widths show multiple cards per row. The **Microsoft 365 Directory** page
(`microsoft-365/directory.blade.php`, non-admin view) uses the same `96rem` width so
its people/groups tables are not squished. The rest of the portal retains the
standard `64rem` content width via the `content-class` prop default (`max-w-portal`)
on `x-app-layout`.

`portal:prewarm-client-dashboards` prioritises missing SuperOps snapshots for every
active client with a SuperOps Account ID. **Extra Sync (Entra)** is queued by
`portal:sync-entra-users` on the **same adaptive cadence** (when `ENTRA_SYNC_ENABLED`) via
`schedule:run` — separate from SuperOps/M365 prewarm. Production must keep minute
schedule + workers alive (systemd timers + root crontab on app.onit.ltd —
[Deployment.md](Deployment.md#10-configure-cron--systemd-timers)). Multi-day Entra ages
on Integration Health usually mean the minute runner was dead, not Graph API rate limits.

### Open ticket statuses

Verified against live SuperOps status enums (2026-07-15):

`Open`, `In Progress`, `On Hold`, `Pending`, `Reopened`, `Waiting on Client`, `Waiting on Customer`, `Waiting on Vendor`

### Closed ticket statuses

`Closed`, `Closed (no response)`, `Resolved`, `Cancelled`

Closed counts use `resolutionTime`, not `createdTime`. Unknown statuses are excluded from both buckets and logged.

### SuperOps GraphQL request shape

Built from [developer.superops.com/msp](https://developer.superops.com/msp) and live-verified against the On IT tenant (2026-07-15). Auth matches the working Python scripts:

```http
POST https://api.superops.ai/msp
Content-Type: application/json
Authorization: Bearer <SUPEROPS_API_TOKEN>
CustomerSubDomain: onitltd
```

Body: `{ "query": "...", "variables": { "input": { ... } } }`

`ListInfoInput` (docs):

| Field | Type | Use |
|-------|------|-----|
| `page` | Int | 1-based page |
| `pageSize` | Int | page size (dashboard uses 100 for tickets, 1 for asset count) |
| `condition` | RuleConditionInput | `{ "attribute", "operator", "value" }` |
| `sort` | [SortInput] | array of `{ "attribute", "order": "ASC"|"DESC" }` |

Client-scoped filter (works for tickets and assets):

```json
{ "attribute": "client.accountId", "operator": "is", "value": "<clients.superops_account_id>" }
```

`client.name` with operator `is` also works. Do **not** use `field` / `eq`.

Hard SuperOps quirks (live-verified):

1. `getTicketList` must select `ticketId` — otherwise `totalCount` is set but `tickets` is empty.
2. `getAssetList` must select at least one asset field (e.g. `assetId`) — a `listInfo`-only selection returns **Internal Server Error**.
3. Date-range ticket filters are unreliable; the dashboard pages the client’s tickets and computes 7/14/30/all in PHP from `createdTime` / `resolutionTime`.

Ticket query shape used by the dashboard:

```graphql
query getTicketList($input: ListInfoInput!) {
  getTicketList(input: $input) {
    tickets { ticketId displayId status createdTime resolutionTime client }
    listInfo { totalCount hasMore }
  }
}
```

Asset count query shape:

```graphql
query getAssetList($input: ListInfoInput!) {
  getAssetList(input: $input) {
    assets { assetId }
    listInfo { totalCount hasMore }
  }
}
```

If the asset query fails, tickets still cache and assets show as unavailable.

### SuperOps launch

Dashboard action buttons use `GET /integrations/superops/launch` — the same Client SSO entry as the main portal SuperOps tile (`/#/requester/login`). The dashboard is a **high-level snapshot only**; tickets, assets, and full detail live in SuperOps.

## Huntress security metrics

Service: `App\Services\Huntress\HuntressClientMetricsService`

Cache key: `client:{client_id}:huntress-security:v1`

Per-client link: `clients.huntress_organization_id` (Admin → Clients). Organisation ID is from Huntress → Organizations.

| Setting | Env | Default |
|---------|-----|---------|
| Enabled | `HUNTRESS_ENABLED` | `false` |
| API key | `HUNTRESS_API_KEY` | — |
| API secret | `HUNTRESS_API_SECRET` | — |

**Access is free** for Huntress partner accounts (generate key/secret in portal Account Settings). Auth: HTTP Basic to `https://api.huntress.io/v1`. Docs: [api.huntress.io/docs](https://api.huntress.io/docs).

Refresh: `GET /v1/organizations/{id}` maps:

| Cache field | API sources (first match) |
|-------------|---------------------------|
| `agents_total` | `edr.agents_count`, flat `agents_count` |
| `agents_unresponsive` | `edr.unresponsive_agents_count` |
| `open_incidents` | `open_incident_reports_count` |
| `edr_isolated_agents` | `edr.isolated_agents_count` |

Client Admin tile **Security (Huntress):** open incidents hero + agents / unresponsive / isolated. Amber when open or isolated &gt; 0.

Background: `RefreshHuntressSecurityJob` on **`high`** (started/last_result for Integration Health). Prewarm uses adaptive requeue (`PortalFreshnessService`). Smoke: `php artisan portal:probe-security-apis --huntress-org=…` (or `--client=`).

## Microsoft 365 directory (async)

Service: `App\Services\M365\M365DirectoryService`

- Page reads cached snapshot immediately (`m365_directory.client.{id}`).
- Stale (client soft wording) after `ENTRA_DIRECTORY_CACHE_MINUTES` (default **5**); still served up to `ENTRA_DIRECTORY_STALE_MINUTES` (default 1440).
- Prewarm / Integration Health requeue after `ENTRA_DIRECTORY_REFRESH_AFTER_MINUTES` (default **2.5**) with the shared prewarm cadence.
- Background refresh: `RefreshM365DirectoryJob` with lock `m365_directory.refresh.{id}` — queued (not `afterResponse()`). Same queue worker requirement as SuperOps metrics; see [Deployment.md](Deployment.md#11-run-the-queue-worker).
- Client Admin manual refresh: `POST /microsoft-365/directory/refresh` (cooldown `ENTRA_DIRECTORY_REFRESH_COOLDOWN_SECONDS`, default 60).
- Graph refresh reads `assignedLicenses` with the tenant user list and resolves display names with one `/subscribedSkus` request. The eligible-user result carries those SKU names into the directory snapshot, so it does not repeat per-user `licenseDetails` calls.
- Mailbox type detection still reads each user's `mailboxSettings` serially. A future optimisation can use Graph `$batch` in chunks of 20; this remains separate to keep shared-mailbox classification and its per-user error handling unchanged.
- **Live UI (not full-page reload):** directory chrome stays put; only the data panel polls `GET /microsoft-365/directory/live` (or admin `…/microsoft-365/live`) every **5 seconds** while cold or refreshing. Cached tables stay visible during refresh. Large tenants can take several minutes (mailboxSettings per user) — the panel says so; it does not imply an 8-second finish.
- **Client Admin overview** (`/client-admin`): metrics panel polls `GET /client-admin/live` while any refresh is in progress (no full-page reload).
- **Do not** run `php artisan cache:clear` on routine deploys — that wipes `m365_directory.client.{id}` snapshots and forces a cold “Directory synchronising” empty state until Graph rebuild finishes. Prefer `route:clear` / `config:clear` / `view:clear` / `optimize` — [Deployment.md](Deployment.md).
- **Admin Integration Health** (`/admin`): separate live poll every 5s (fragment endpoint).

MSP staff view client directory at `/admin/clients/{client}/microsoft-365` (unchanged).

## Microsoft 365 insights (Client Admin)

Service: `App\Services\M365\M365InsightsService`  
SKU labels / free-seat rules: `App\Services\M365\MicrosoftLicenseSkuNames`

**UI:** Client Admin organisation overview (`/client-admin`) — hero card “Microsoft 365” % and section “Microsoft 365 licence insight”.

Cache key: `client:{client_id}:m365-insights:v3` (v3 = exclude preview/IW pools + disambiguate duplicate names; older v1/v2 ignored)

Source: Microsoft Graph `/subscribedSkus` (enabled user SKUs only). Licensed user count reuses `M365DirectorySnapshot` when present, otherwise counts tenants users with assigned licences.

### Seat totals + overall utilisation %

Counts **paid / commercial seats only**. Excluded from overall purchased/assigned/%:

| Rule | Why |
|------|-----|
| Prepaid seats ≥ 100,000 | Free bulk pools (e.g. `FLOW_FREE` = 1,000,000) |
| Exact free SKUs (`FLOW_FREE`, `POWER_BI_STANDARD`, Teams Exploratory, …) | Not bought seats |
| Part number contains `PREVIEW`, `MADEIRA`, `_TRIAL`, `_FREE`, `_VIRAL`, `EXPLORATORY`, `DEVELOPER`, `_IW` | Free / IW / preview pools (e.g. `PROJECT_MADEIRA_PREVIEW_IW_SKU` = 10,000 seats) |

Free/preview rows still appear in the licence list marked **Free / preview**, but do not affect Seats assigned or Overall utilisation %.

Duplicate marketing names (e.g. two “Business Premium” Graph SKUs) are disambiguated with `· {skuPartNumber}`.

So a tenant with Business Premium full and Project Madeira / Power Automate Free pools does **not** show ~1% overall utilisation.

**“Seats assigned / purchased”** on the insight panel uses the same paid-only totals.

### SKU row display

| Field | What clients see |
|-------|------------------|
| Name | Friendly product name from map (e.g. `SPB` → **Microsoft 365 Business Premium**, `EXCHANGEENTERPRISE` → **Exchange Online (Plan 2)**). Unknown SKUs humanized. |
| Counts | assigned / purchased |
| % | Per-SKU utilisation for paid products; free products show **· Free** instead of a misleading 0% |

Top five list prefers paid SKUs first, then free.

### Settings

| Setting | Env | Default |
|---------|-----|---------|
| Fresh TTL (client soft note) | `M365_INSIGHTS_CACHE_MINUTES` | **5** minutes |
| Prewarm requeue | `M365_INSIGHTS_REFRESH_AFTER_MINUTES` | **2.5** minutes |
| Stale retention | `M365_INSIGHTS_STALE_MINUTES` | 1440 minutes |
| Manual refresh cooldown | `M365_INSIGHTS_REFRESH_COOLDOWN_SECONDS` | 60 seconds |

Background refresh: `RefreshM365InsightsJob` (`ShouldQueue` + `ShouldBeUnique`). Fails keep last cache as stale. Same queue worker as SuperOps (`high,default` preferred).

After deploy / mapping change: run `php artisan portal:prewarm-client-dashboards` or client **Refresh now** so v2 caches rebuild.

## Dropsuite / NinjaOne SaaS Backup

Service: `App\Services\Dropsuite\DropsuiteClientMetricsService`

Cache key: `client:{client_id}:dropsuite-backup:v1`

Client mapping: `clients.dropsuite_organization_id` (Admin → Clients). Value is the partner **account/organisation id** from NinjaOne SaaS Backup (Dropsuite).

| Setting | Env | Default |
|---------|-----|---------|
| Enabled | `DROPSUITE_ENABLED` | `false` |
| API base URL | `DROPSUITE_API_URL` | `https://dropsuite.us/api` |
| Reseller token | `DROPSUITE_RESELLER_TOKEN` | — |
| Auth / access token | `DROPSUITE_AUTH_TOKEN` | — |

**Free for partners** that already use Dropsuite/NinjaOne SaaS Backup: Settings → API Settings (URL + reseller + auth tokens). Resellers typically have **GET-only**.

HTTP headers (sent together so both common contracts work):

- `X-Access-Token` + `X-Reseller-Token`
- `Authorization: Token {auth}`

Refresh tries detail paths in order (`accounts/{id}/`, `organizations/{id}/`, backup-summary variants), then list endpoints and filters by id. Maps protected mailbox/seat counts and failure/status into the Client Admin **Backups** card (hero count + status + failed count).

Background: `RefreshDropsuiteBackupJob` on **`high`**, adaptive requeue, Integration Health column. Keep `DROPSUITE_ENABLED=false` until `php artisan portal:probe-security-apis --dropsuite-org=…` succeeds against real tokens. Full method list is on partner **Browsable API** / PDF (portal-gated).

## Promoting users

On IT staff (`account_manager`, `super_admin`) assign roles in **Admin → Clients → Users**. Role changes log `user.role_changed` in activity logs with previous and new role.

## Testing safely

PHPUnit mocks Graph, SuperOps, and Huntress — no live API calls. To verify in staging, use a client with `superops_account_id` / `huntress_organization_id` and Entra tenant configured; open `/client-admin` as a `client_admin` user.

## Change log

| Date | Change |
|------|--------|
| 2026-08-05 | Huntress + Dropsuite: full Client Admin tiles, adaptive requeue, Integration Health, dual Dropsuite auth, `portal:probe-security-apis` |
| 2026-08-05 | Integration Health: adaptive cadence docs complete; sticky nav; timing as side drawer |
| 2026-08-05 | Integration Health: timing settings as side drawer; live metrics are the main page |
| 2026-08-05 | Integration Health UX: collapsible Auto-refresh timing (localStorage), spacing/field widths, mobile stack for clients & jobs |
| 2026-08-05 | Root cause of multi-hour “aging”: stuck `cache_locks` + `onOneServer()` on single Plesk host blocked prewarm; removed it, clear long-lived schedule locks, minute scheduler tick, plain-English Integration Health UI |
| 2026-08-05 | Integration Health own Staff Admin nav tab (`/admin/integration-health`); dashboard only summary card; portal nav Organisation vs Staff Admin |
| 2026-08-05 | Integration Health pipeline panel: prewarm heartbeat, live jobs, flags, DUE status, blocker text |
| 2026-08-05 | Client-friendly vs technician copy on Client Admin + M365 directory; SuperOps requeue at 10m (before 15m client note); Integration Health **aging** past SLA — reduces ~20m lag from 15+5 cadence |
| 2026-08-05 | Technician Integration Health table on Admin Dashboard; dual queue workers; M365 directory/insights on `high`; clear stuck queue flags; no stale page-view auto-queue |
| 2026-08-05 | Auto-reload browser every 8s while M365 directory / Client Admin refresh is in progress |
| 2026-08-05 | M365 utilisation ignores free/bulk SKUs (e.g. FLOW_FREE 1M seats); friendly SKU display names on Client Admin |
| 2026-08-04 | Dashboard metrics pre-stored: cold SuperOps always prewarms (even under deep queue), `high` queue before Entra, page views no longer stampede refresh, 7-day cache retention |
