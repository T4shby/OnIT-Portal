# Client Admin dashboard

Client Admins (`client_admin`) see an organisation overview at `/client-admin` with SuperOps metrics for their own client only.

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

Cache key: `client:{client_id}:superops-dashboard:v1`

| Setting | Env | Default |
|---------|-----|---------|
| Fresh TTL | `SUPEROPS_DASHBOARD_CACHE_MINUTES` | 10 minutes |
| Stale retention | `SUPEROPS_DASHBOARD_STALE_MINUTES` | 1440 minutes |
| Manual refresh cooldown | `SUPEROPS_DASHBOARD_REFRESH_COOLDOWN_SECONDS` | 60 seconds |

Background refresh: `RefreshSuperOpsDashboardJob` — queued to the `jobs` table (`QUEUE_CONNECTION=database` in production). Requires a queue worker on Plesk; see [Deployment.md — Run the queue worker](Deployment.md#11-run-the-queue-worker).

### Open ticket statuses

Verified against live SuperOps data (3R Systems, 2026-07-15):

`Open`, `In Progress`, `On Hold`, `Pending`, `Reopened`, `Waiting on Client`, `Waiting on Customer`, `Waiting on Vendor`

### Closed ticket statuses

`Closed`, `Closed (no response)`, `Resolved`, `Cancelled`

Closed counts use `resolutionTime`, not `createdTime`. Unknown statuses are excluded from both buckets and logged.

### SuperOps GraphQL request shape

The dashboard intentionally follows the working Python reference scripts: `POST https://api.superops.ai/msp` with:

- `Authorization: Bearer <SUPEROPS_API_TOKEN>`
- `CustomerSubDomain: onitltd`
- JSON body `{ "query": "...", "variables": { "input": { "page": 1, "pageSize": 100 } } }`

Do **not** rely on SuperOps server-side `condition` filters for dashboard metrics. Production returned `Internal Server Error(s) while executing query` for client-scoped dashboard filters on 2026-07-15. The portal now uses unfiltered `getTicketList` / `getAssetList` calls and filters records by `client.accountId` in PHP against `clients.superops_account_id`.

`SortInput` for the dashboard ticket list follows the proven Python shape:

```json
{ "sort": { "attribute": "displayID", "order": "DESC" } }
```

Do **not** use `field` / `eq` — the live API rejects those with a ValidationError. Requester-specific `/support` filtering is separate from these dashboard metrics.

Date-range ticket metrics cannot be filtered server-side (date operators return Internal Server Error). The dashboard pages tickets (`pageSize` 100), keeps only matching `client.accountId`, and computes 7/14/30/all ranges in PHP from `createdTime` / `resolutionTime`.

### Assets

`getAssetList` is read without a `condition`; the portal requests `assets { client }`, filters by `client.accountId`, and counts the matches. If SuperOps rejects asset list fields, the dashboard logs `SuperOps dashboard asset count unavailable`, stores ticket metrics, and shows assets as unavailable rather than blanking the whole dashboard.

### SuperOps launch

Dashboard action buttons use `GET /integrations/superops/launch` — the same Client SSO entry as the main portal SuperOps tile (`/#/requester/login`). The dashboard is a **high-level snapshot only**; tickets, assets, and full detail live in SuperOps.

## Microsoft 365 directory (async)

Service: `App\Services\M365\M365DirectoryService`

- Page reads cached snapshot immediately (`m365_directory.client.{id}`).
- Stale after `ENTRA_DIRECTORY_CACHE_MINUTES` (default 15); still served up to `ENTRA_DIRECTORY_STALE_MINUTES` (default 1440).
- Background refresh: `RefreshM365DirectoryJob` with lock `m365_directory.refresh.{id}` — queued (not `afterResponse()`). Same queue worker requirement as SuperOps metrics; see [Deployment.md](Deployment.md#11-run-the-queue-worker).
- Client Admin manual refresh: `POST /microsoft-365/directory/refresh` (cooldown `ENTRA_DIRECTORY_REFRESH_COOLDOWN_SECONDS`, default 60).
- Graph refresh reads `assignedLicenses` with the tenant user list and resolves display names with one `/subscribedSkus` request. The eligible-user result carries those SKU names into the directory snapshot, so it does not repeat per-user `licenseDetails` calls.
- Mailbox type detection still reads each user's `mailboxSettings` serially. A future optimisation can use Graph `$batch` in chunks of 20; this remains separate to keep shared-mailbox classification and its per-user error handling unchanged.

MSP staff view client directory at `/admin/clients/{client}/microsoft-365` (unchanged).

## Promoting users

On IT staff (`account_manager`, `super_admin`) assign roles in **Admin → Clients → Users**. Role changes log `user.role_changed` in activity logs with previous and new role.

## Testing safely

PHPUnit mocks Graph and SuperOps — no live API calls. To verify in staging, use a client with `superops_account_id` and Entra tenant configured; open `/client-admin` as a `client_admin` user.
