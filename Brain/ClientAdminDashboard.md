# Client Admin dashboard

Client Admins (`client_admin`) see an organisation overview at `/client-admin` scoped to their own client. The page answers:

1. **Are my systems healthy?** — devices online/offline, Huntress security, Dropsuite backups, M365 utilisation
2. **Are my issues being dealt with?** — open tickets (with priority breakdown and table), SLA %, ticket activity
3. **What value am I getting from On IT?** — licence utilisation and coverage metrics

## Roles

| Role | Code | Capabilities |
|------|------|--------------|
| Client Requester | `client_requester` | Standard portal, support, SSO launch |
| Client Billing Admin | `client_billing_admin` | Requester + billing capability (Pax8 UI not built yet) |
| Client Admin | `client_admin` | Billing + org overview, M365 directory, SuperOps summary |

Staff roles (`account_manager`, `super_admin`) are unchanged.

Entra sync creates new users as `client_requester` only. Sync updates never change `role`, so manually promoted `client_billing_admin` and `client_admin` users are preserved.

## Technician Integration Health (Admin Dashboard)

**Who:** On IT `super_admin` / `account_manager` only — **Admin → Dashboard** (`/admin`).  
**Not** shown on Client Admin / requester portals.

Service: `App\Services\Admin\IntegrationHealthService`

Per **active** client (scoped by account manager access when applicable):

| Column | Meaning |
|--------|---------|
| SuperOps | Last successful dashboard cache + last job duration |
| M365 directory | Last directory snapshot meta + duration |
| M365 licences | Last insights cache (`m365-insights:v3`) |
| Entra sync | `clients.entra_synced_at` + last SyncEntra job |
| Active / stuck | Process currently queued or running; **stuck** if started &gt; 5 minutes ago |

Also shows queue depth (`jobs` high/default/failed) and oldest pending age.

**Live UI:** `/admin` polls `GET /admin/integration-health` every **5 seconds** (pauses when the tab is hidden) and replaces the health table + queue pending card. No full-page F5 required while a sync runs.

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

| Setting | Env | Default |
|---------|-----|---------|
| Fresh TTL (stale banner) | `SUPEROPS_DASHBOARD_CACHE_MINUTES` | 15 minutes (set in production `.env`; code default 60) |
| Cache retention | `SUPEROPS_DASHBOARD_STALE_MINUTES` | 10080 minutes (7 days) |
| Manual refresh cooldown | `SUPEROPS_DASHBOARD_REFRESH_COOLDOWN_SECONDS` | 60 seconds |
| GraphQL page cap | `SUPEROPS_DASHBOARD_MAX_PAGES` | 10 pages × 100 rows |

Background refresh: `RefreshSuperOpsDashboardJob` on the **`high`** queue (before Entra/SCIM on `default`). Worker must run `--queue=high,default` — [Deployment.md](Deployment.md#11-run-the-queue-worker).

**Data should exist before anyone opens the page:**

1. Scheduler runs `portal:prewarm-client-dashboards` **every 5 minutes**.
2. **SuperOps cold + stale always queues** (not optional), even when the jobs table is deep. Clears orphaned `refresh_queued` when no matching `jobs` row — that flag previously blocked refreshes for 30–40+ minutes while workers were idle.
3. M365 / Huntress / Dropsuite only when cold or past their fresh window, and only when spare queue capacity (&lt; 40 pending).
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
active client with a SuperOps Account ID. Production requires both Plesk tasks:
minute-by-minute `schedule:run` and the separate queue worker documented in
[Deployment.md](Deployment.md#10-configure-cron).

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

## Huntress security metrics (Phase 3 scaffold)

Service: `App\Services\Huntress\HuntressClientMetricsService`

Cache key: `client:{client_id}:huntress-security:v1`

Per-client link: `clients.huntress_organization_id` (optional; set on Admin → Clients create/edit).

| Setting | Env | Default |
|---------|-----|---------|
| Enabled | `HUNTRESS_ENABLED` | `false` |
| API key | `HUNTRESS_API_KEY` | — |
| API secret | `HUNTRESS_API_SECRET` | — |

Auth: HTTP Basic to `https://api.huntress.io/v1` (key = username, secret = password).

Refresh loads `GET /v1/organizations/{huntress_organization_id}` and maps EDR agent totals, unresponsive/isolated counts, and open incident counts when present. Missing org ID or disabled/unconfigured API returns an unavailable summary (no hard failure). API errors keep the last successful cache as stale.

Background refresh: `RefreshHuntressSecurityJob` (`ShouldQueue` + `ShouldBeUnique`) — same database queue worker as SuperOps; see [Deployment.md](Deployment.md#11-run-the-queue-worker).

Dashboard UI for Huntress tiles is not wired yet — this is the metrics scaffold only.

## Microsoft 365 directory (async)

Service: `App\Services\M365\M365DirectoryService`

- Page reads cached snapshot immediately (`m365_directory.client.{id}`).
- Stale after `ENTRA_DIRECTORY_CACHE_MINUTES` (default 15); still served up to `ENTRA_DIRECTORY_STALE_MINUTES` (default 1440).
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
| Fresh TTL | `M365_INSIGHTS_CACHE_MINUTES` | 15 minutes |
| Stale retention | `M365_INSIGHTS_STALE_MINUTES` | 1440 minutes |
| Manual refresh cooldown | `M365_INSIGHTS_REFRESH_COOLDOWN_SECONDS` | 60 seconds |

Background refresh: `RefreshM365InsightsJob` (`ShouldQueue` + `ShouldBeUnique`). Fails keep last cache as stale. Same queue worker as SuperOps (`high,default` preferred).

After deploy / mapping change: run `php artisan portal:prewarm-client-dashboards` or client **Refresh now** so v2 caches rebuild.

## Dropsuite / NinjaOne SaaS Backup scaffold

Service: `App\Services\Dropsuite\DropsuiteClientMetricsService`

Cache key: `client:{client_id}:dropsuite-backup:v1`

Client mapping field: `clients.dropsuite_organization_id` (optional). If it is empty, the Client Admin dashboard shows Dropsuite as unavailable for that organisation.

Configuration lives under `services.dropsuite`: `api_url` (default `https://dropsuite.us/api`), `reseller_token`, `auth_token`, and `enabled`.

Background refresh: `RefreshDropsuiteBackupJob` — queued with the same Client Admin refresh action as SuperOps. The scaffold fetches `organizations/{dropsuite_organization_id}/backup-summary/` through `DropsuiteApiClient::get()` and normalises common summary keys into protected mailbox count, failed backup count, and a `success` / `warning` / `unknown` status. If the API is not configured or the endpoint shape differs, the dashboard falls back gracefully instead of hard-failing.

## Promoting users

On IT staff (`account_manager`, `super_admin`) assign roles in **Admin → Clients → Users**. Role changes log `user.role_changed` in activity logs with previous and new role.

## Testing safely

PHPUnit mocks Graph, SuperOps, and Huntress — no live API calls. To verify in staging, use a client with `superops_account_id` / `huntress_organization_id` and Entra tenant configured; open `/client-admin` as a `client_admin` user.

## Change log

| Date | Change |
|------|--------|
| 2026-08-05 | Technician Integration Health table on Admin Dashboard; dual queue workers; M365 directory/insights on `high`; clear stuck queue flags; no stale page-view auto-queue |
| 2026-08-05 | Auto-reload browser every 8s while M365 directory / Client Admin refresh is in progress |
| 2026-08-05 | M365 utilisation ignores free/bulk SKUs (e.g. FLOW_FREE 1M seats); friendly SKU display names on Client Admin |
| 2026-08-04 | Dashboard metrics pre-stored: cold SuperOps always prewarms (even under deep queue), `high` queue before Entra, page views no longer stampede refresh, 7-day cache retention |
