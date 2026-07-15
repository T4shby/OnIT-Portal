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

## SuperOps metrics

Service: `App\Services\SuperOps\SuperOpsClientMetricsService`

Cache key: `client:{client_id}:superops-dashboard:v2`

Dashboard payload includes: asset totals with online/offline split, open ticket count with priority breakdown, open-ticket table (top 10), resolution SLA % (30 days), and ticket logged/closed ranges.

| Setting | Env | Default |
|---------|-----|---------|
| Fresh TTL | `SUPEROPS_DASHBOARD_CACHE_MINUTES` | 10 minutes |
| Stale retention | `SUPEROPS_DASHBOARD_STALE_MINUTES` | 1440 minutes |
| Manual refresh cooldown | `SUPEROPS_DASHBOARD_REFRESH_COOLDOWN_SECONDS` | 60 seconds |

Background refresh: `RefreshSuperOpsDashboardJob` — queued to the `jobs` table (`QUEUE_CONNECTION=database` in production). Requires a queue worker on Plesk; see [Deployment.md — Run the queue worker](Deployment.md#11-run-the-queue-worker).

The dashboard uses a wide (`96rem`) layout and compact responsive grids so desktop
and tablet widths show multiple cards per row. The rest of the portal retains the
standard `64rem` content width.

`portal:prewarm-client-dashboards` queues all configured integration refresh jobs
for every active client. Laravel schedules it every ten minutes, so dashboard
data is populated before a client visits. Production requires both Plesk tasks:
minute-by-minute `schedule:run` and the separate queue worker documented in
[Deployment.md](Deployment.md#10-configure-cron).

### Open ticket statuses

Verified against live SuperOps data (3R Systems, 2026-07-15):

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

MSP staff view client directory at `/admin/clients/{client}/microsoft-365` (unchanged).

## Microsoft 365 insights (Phase 2 service layer)

Service: `App\Services\M365\M365InsightsService`

Cache key: `client:{client_id}:m365-insights:v1`

The service asynchronously builds Client Admin summary data from Microsoft Graph `/subscribedSkus`: licensed user count, purchased and assigned user-license seats, overall utilisation, and the five SKUs with the most assigned seats. Only user SKUs whose capability status is enabled are included. If the existing `M365DirectorySnapshot` is cached, its user count is reused; otherwise the service counts tenant member users with assigned licences.

| Setting | Env | Default |
|---------|-----|---------|
| Fresh TTL | `M365_INSIGHTS_CACHE_MINUTES` | 15 minutes |
| Stale retention | `M365_INSIGHTS_STALE_MINUTES` | 1440 minutes |
| Manual refresh cooldown | `M365_INSIGHTS_REFRESH_COOLDOWN_SECONDS` | 60 seconds |

Background refresh: `RefreshM365InsightsJob` (`ShouldQueue` + `ShouldBeUnique`) using the same database queue worker as the directory and dashboard metrics. Graph or refresh failures preserve the last cached summary as stale. This phase is service-only; no Blade dashboard components are wired yet.

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
