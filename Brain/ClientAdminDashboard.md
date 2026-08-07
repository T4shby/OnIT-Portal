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
## Shared client visibility (people + systems)

Service: `App\Services\Portal\ClientVisibilityService`

One rule for Organisation overview, SuperOps tickets, M365 people/directory, Huntress cases, Dropsuite tiles, and future feeds.

**Do not conflate Client Admin with Technician Admin.**

| Who | Scope | What they see |
|-----|-------|----------------|
| **Technician Admin** (`super_admin`, `account_manager`) | **All customers** they can access (`super_admin` = every client; AM = assigned clients) | Full org data for each of those customers. Cross-customer view via **Staff Admin** (Integration Health, Clients, per-client tools). |
| **Client Admin** (`client_admin`) | **Only their customer** | All people + all systems for **that one** organisation (devices, tickets, licences, backups, security cases). Never other customers. |
| **Requester / Billing Admin** | Their customer, personal only | **Their** tickets, **their** M365 person row, **their** Huntress cases, **their** Dropsuite last backup time — not colleagues’. |
| Other organisations | — | Never (client-facing users cannot cross tenants) |

Identity match: work email (preferred), SuperOps requester id when present, else name/local-part on the record. Personal users cannot run org-wide **Refresh**.

New feeds: use `ClientVisibilityService::canViewOrganisationWide` / `matchesPerson` — do not invent a one-off gate.

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

**What's going on ↔ timing drawer:** The summary always shows the **same** DB values as the drawer (`PortalFreshnessService::configuredTiming()` / snapshot `configured`). Line 1 = current effective target (which mode is active + minutes). Line 2 = Fast / Idle business / Idle outside / Active session / Hours / timezone. Editing the drawer updates line 2 live (marks unsaved); **Save timing** persists and the next poll / redirect drives both lines from DB. Saving clears the freshness snapshot cache.

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
| **Never loaded** (`cold`) | Sold + mapped product with **no successful snapshot yet** — incomplete, not “fine” |

**Cold is a platform warning:** sold feeds with status `cold` raise pipeline `severity_level` **warning**, change the headline away from “All systems refreshing normally”, list per-client notices, amber cell copy, and row highlight. Do **not** treat “other columns Up to date” as all-clear when any entitled feed has never loaded.

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
| Status **cold** / **Never loaded** | No successful cache for a sold/mapped feed — warning headline + notice (not OK just because SuperOps is green) |

Prewarm writes cache key `portal.prewarm.last_run` every run for the heartbeat.

**Severity order (headline):** scheduler dead / stuck schedule locks / prewarm late / worker lag → then **cold sold feeds** → then “jobs pending” (info) → OK.

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
| **Client Admin** | `/client-admin` org overview | Tile only if **product entitled** (sold). **Setup needed** (sold, no org ID / platform) → “Please contact your account manager to get this sorted.” Linked/live → metrics. Not sold → **hidden**. |
| **Requester / Billing** | `/client-admin` personal | Show tile only when product **live** (entitled + mapped + platform). Unsold or setup-needed products hidden. |
| Technician (`super_admin` / `account_manager`) on same URL | `/client-admin` (when staff uses client switch) | Raw unavailable reasons; age / requeue notes; Integration Health. |
| Technician only | Admin → Integration refresh health | Ages, `OK` / **AGING**, Not sold / Setup needed; queue depths. |

## Product entitlements (sold services vs licence vendors)

Policy: `App\Services\Portal\ClientProductService`. Storage: `clients.product_entitlements` JSON.

**Two catalog kinds** (modular):

| Kind | Meaning | Keys today | Client Admin tiles |
|------|---------|------------|--------------------|
| `service` | On IT sells this product to the client | `superops`, `m365`, `huntress`, `dropsuite` | When **entitled**; contact AM if setup needed |
| `licence_vendor` | Where they buy Microsoft / cloud **licences** | `pax8` (more vendors later) | Not system-health tiles — staff assignment + Pax8 launch only |

| Key | Label | Mapping fields |
|-----|--------|----------------|
| `superops` | Devices & tickets | `superops_account_id` (+ SSO flag) |
| `m365` | Microsoft 365 | `entra_tenant_id` |
| `huntress` | Security | `huntress_organization_id` |
| `dropsuite` | Backups | `dropsuite_organization_id` |
| `pax8` | Pax8 (licence vendor) | `pax8_company_id` + `pax8_sso_enabled` |

Status codes: `not_sold` · `setup_needed` · `live` · `platform_down` · `error`.  
Labels differ by kind (e.g. vendor: “Not assigned” / “Assigned” instead of “Not sold” / “Live”).

**Admin UX:** Clients list = one row of equal-size chips `S M H D · P` (dashed = licence vendor). **Colours (solid fill):** green = live/assigned · yellow = setup/link needed · red = platform error · greyed out = not sold/not assigned. Client edit = **Portal products** + **Licence vendor**. Form fields in `resources/views/admin/clients/products/_*.blade.php`.

**Adding a future licence vendor:** (1) key in `KEYS`, (2) catalog entry `kind => licence_vendor`, (3) `isMapped` / `isPlatformReady`, (4) blade `products/_newvendor.blade.php`.

**Single rule:** tiles, gates, prewarm, Integration Health use `isEntitled` / `isMapped` / `shouldRefresh` for **services**. Vendors never prewarm dashboard feeds.

Migration backfill: existing filled IDs → entitled true.

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
4. **`requester` and `client` on ticket/user list fields are leaf `JSON`**, not GraphQL objects. Selecting `requester { userId name email }` or `client { accountId }` fails with `SubSelectionNotAllowed` on the whole refresh — Integration Health freezes age on last success while M365 still looks fine. Select the leaf only and decode arrays/JSON strings in PHP (`normalizeJsonObject`). Same for embedded support (`SuperOpsTicketService`) and user link (`SuperOpsUserSyncService`).

Ticket query shape used by the dashboard:

```graphql
query getTicketList($input: ListInfoInput!) {
  getTicketList(input: $input) {
    tickets {
      ticketId displayId subject status priority
      createdTime resolutionTime
      client requester
    }
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

Client Admin tile **Security (Huntress):** active cases hero + resolved count + agents / unresponsive / isolated. Amber when active or isolated &gt; 0. **View cases** opens the org-scoped case list.

### Incident cases (list + detail)

| Route | Who |
|-------|-----|
| `/security/huntress` | Client-facing users of that organisation only (gate `view-huntress-security`) |
| `/security/huntress/cases/{id}` | Same org + visibility rules below |
| `/admin/clients/{client}/security/huntress` | Staff with **view** on that client — full org security dashboard (metrics tiles + all cases). Same entry pattern as **View Microsoft 365 directory** from Admin → Clients → Edit. |
| `/admin/clients/{client}/security/huntress/cases/{id}` | Staff case detail for that client |

Staff open: **Admin → Clients → Edit → Client tools → View Huntress security** (requires numeric org ID + `HUNTRESS_ENABLED`). Optional **Open in Huntress** uses `HUNTRESS_CONSOLE_BASE_URL` + `/org/{id}/command_center`.


| Role | Cases they see |
|------|----------------|
| **Client Admin** | All Huntress cases for their organisation |
| **On IT staff** (SA / AM with client access) | All cases for that client |
| **Requester / Billing Admin** | Only cases linked to **them** (email/name on the report) — not colleagues’ cases |

Service: `App\Services\Huntress\HuntressIncidentService`  
Cache: `client:{id}:huntress-incidents:v1` (refreshed with `RefreshHuntressSecurityJob` / org metrics)

“Linked to them” matches work **email** (or local-part / display name) against subject, summary, body, and extracted emails on the report. Wrong-org IDs return **404**. Regular users cannot refresh the org-wide cache.

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
API client: `DropsuiteApiClient`  
Contract: sub-reseller **REST API for Sub-reseller v1.00** (partner PDF).

Cache key: `client:{client_id}:dropsuite-backup:v2`

Client mapping: `clients.dropsuite_organization_id` (Admin → Clients). Value is the Dropsuite **organization_id** on backed-up accounts (`user.organization_id` in `GET /accounts`).

| Setting | Env | Default |
|---------|-----|---------|
| Enabled | `DROPSUITE_ENABLED` | `false` |
| API base URL | `DROPSUITE_API_URL` | `https://dropsuite.uk/api` (UK). US hosts use `https://dropsuite.us/api` |
| Reseller token | `DROPSUITE_RESELLER_TOKEN` | Partner **Reseller Token** (numeric id is normal) |
| Access / auth token | `DROPSUITE_AUTH_TOKEN` | OpenAPI **Admin Token** (list users / resellers) or **User Token** (mailboxes for that user). UI may call these Authentication / Secret — they are not interchangeable blindly |

/** Auth (live UK): X-Reseller-Token = Reseller Token (UUID). X-Access-Token = Authentication Token from API Information (Admin — lists `GET /users`). Per-org mailbox list uses each user’s `authentication_token` as User Token for `GET /accounts`. Secret Token is not used for these GETs. **/

**Primary refresh path:** `GET /users` (Admin Authentication Token) → pick that org’s `authentication_token` → `GET /accounts` as User Token (paginated `result_set`). Fields: `email`, `last_backup`, `current_backup_status`, `errors`, `display_name`, `user.organization_id`. Optional: `GET /onedrives` with the same user token.

### Who sees what

| Who | Scope | Dropsuite view |
|-----|-------|----------------|
| **Technician Admin** (`super_admin` / `account_manager`) | All accessible customers | Full org backup health per client: **Staff Admin → Clients → Edit → View Dropsuite backups**, Integration Health Dropsuite column. |
| **Client Admin** (`client_admin`) | **Their customer only** | **Organisation overview → Backups (Dropsuite):** org totals (protected mailbox count, latest backup, failures, OneDrive) **and a scrollable list of every protected mailbox** with last backup time + status. Never other customers. |
| **Requester / Billing Admin** | Personal only | Same overview page tile **My backup**: last time **their** work-email mailbox was backed up. No colleague list, no org totals. Matched by email on the cached org snapshot. |

Shared rule: `ClientVisibilityService` (same as Huntress / tickets). Technician ≠ Client Admin ≠ requester.

Background: `RefreshDropsuiteBackupJob` on **`high`**, adaptive requeue, Integration Health column. Keep `DROPSUITE_ENABLED=false` until `php artisan portal:probe-security-apis --dropsuite-org=…` succeeds (requires tokens filled **and** `DROPSUITE_ENABLED=true` for `isConfigured()`). Do not commit the partner PDF into git.

### Production enable (ops)

| Ready on prod (2026-08-06) | Status |
|---|---|
| Code | Live — users → per-org accounts path |
| API URL | `https://dropsuite.uk/api` |
| Reseller Token | UUID from API Information (**not** the short numeric reseller id) |
| Authentication Token | Admin token (lists users) |
| `DROPSUITE_ENABLED` | **true** (after successful probe) |
| Sample probe | org `10879` Reid & Rose: 3 mailboxes; org `5979` On IT NFR: 23 mailboxes |
| Client map | Client #1 On IT Technology Partners → `5979` (NFR). Other Dropsuite orgs need Admin → Clients mapping |

**Dropsuite organizations seen under On IT reseller (map `dropsuite_organization_id`):**

| organization_id | Name (API) |
|---:|---|
| 5979 | On IT LTD [789e] - NFR |
| 6182 | YorPower |
| 6185 | Stelvio Group |
| 6192 | Forest Care Selection |
| 6494 | Transfer Brand Solutions |
| 6504 | SLS Recruitment |
| 9096 | Urbana Town Planning |
| 10831 | Premier Fleet Solutions |
| 10879 | Reid & Rose Accounting |
| 11223 | Finishing Design Services |
| 12720 | Northern Property Partners |
| 13469 | Schneiderfm.co.uk |
| 14494 | GreenView Project |

Secret Token is not required for current GET users/accounts flow.


## Promoting users

On IT staff (`account_manager`, `super_admin`) assign roles in **Admin → Clients → Users**. Role changes log `user.role_changed` in activity logs with previous and new role.

## Testing safely

PHPUnit mocks Graph, SuperOps, and Huntress — no live API calls. To verify in staging, use a client with `superops_account_id` / `huntress_organization_id` and Entra tenant configured; open `/client-admin` as a `client_admin` user.

## Change log

| Date | Change |
|------|--------|
| 2026-08-07 | Client Admin Dropsuite tile lists **all** protected mailboxes; requester still personal “My backup” only |
| 2026-08-07 | Staff **View Dropsuite backups** on Edit client; Integration Health jobs show client name (fix blank CLIENT from JSON-escaped payloads) |
| 2026-08-07 | Integration Health: **Never loaded** (`cold`) sold feeds raise warning severity/headline/notices + amber UI (no longer hidden under global OK) |
| 2026-08-06 | Dropsuite **live on prod**: UUID Reseller Token + Admin Authentication Token; per-org mailboxes via user tokens from `GET /users`; UK host |
| 2026-08-06 | Prod `main` @ `52bcac9` deployed Dropsuite PDF path; env keys present disabled; wait reseller/access tokens + client org maps before enable |
| 2026-08-06 | Clarify Technician Admin (all customers) vs Client Admin (own customer only) vs requester (personal) — Dropsuite + visibility |
| 2026-08-06 | Dropsuite: PDF `GET /accounts` org filter; Client Admin org-wide backups; requester personal last-backup only |
| 2026-08-06 | Integration Health What's going on summary mirrors Refresh timing drawer values (DB + unsaved live preview) |
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
