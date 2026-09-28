# Architecture - On IT Portal

## Overview

On IT Portal is a server-rendered Laravel 11 monolith. There is no separate
API layer and no SPA - every page is a Blade view rendered on the server,
progressively enhanced with Alpine.js for small interactive bits (live
refresh polling, filter chips) and Tailwind CSS for styling. This is a
deliberate choice for an internal/customer portal of this size; see
`Brain/Decisions.md` before introducing an API, SPA, or service split.

```
Browser
  │  Blade + Alpine.js (no SPA, no separate API)
  ▼
Laravel 11 app (single PHP-FPM pool, Plesk/Ubuntu)
  │
  ├── routes/web.php            single route file, session-auth only
  ├── app/Http/Controllers      thin, authorize-then-act
  ├── app/Policies + Gates      all authorization logic
  ├── app/Services              one subfolder per external integration
  ├── app/Jobs                  queued background work
  └── app/Models                Eloquent, explicit $fillable everywhere
  │
  ▼
MySQL/MariaDB (also backs session, cache, and queue tables)
  │
  └── External platforms (via app/Services/*):
        - Microsoft Graph / Entra ID (SSO + directory + group sync)
        - SuperOps (PSA: tickets, SSO launch)
        - Huntress (security incidents)
        - Dropsuite (backup metrics)
        - Pax8 (SSO launch / billing)
```

## Request flow

1. All routes require Entra ID session auth (`middleware('auth')`), except
   `/login`, `/auth/microsoft`, `/auth/microsoft/callback` (guest-only).
2. `MicrosoftAuthController` completes OAuth via Socialite, looks up an
   **existing, pre-provisioned** `User` row by `entra_object_id` (falling
   back to email), and refuses login for accounts that are inactive,
   portal-login-disabled, or whose `Client` is inactive. There is no
   self-service signup - an administrator must create the user row first
   (`Admin\UserController`).
3. Every subsequent request carries the Laravel session cookie
   (`SESSION_DRIVER=database`). `EnsureUserHasRole` middleware gates whole
   route groups by role category (e.g. `admin/*` requires
   `super_admin`/`account_manager`); individual controller actions then
   call `$this->authorize()` against a Policy or `abort_unless()` against a
   Gate to check the *specific* client/user/record being touched.
4. Controllers call into `app/Services/*` for anything involving an
   external platform or non-trivial business logic. Slow/unreliable calls
   (Entra Graph writes, SCIM provisioning) are dispatched as `app/Jobs/*`
   queued jobs rather than run inline, to avoid the 60s Plesk/nginx gateway
   timeout - the controller returns immediately with a "queued" message and
   the page polls a `/live` endpoint or reloads.
5. Scheduled work (`routes/console.php`) drives cache prewarming (SuperOps,
   M365, Huntress, Dropsuite dashboards), Entra user sync, nightly metric
   snapshots, and activity-log pruning, via `php artisan schedule:run` every
   minute (systemd timer in production, see `docs/DEPLOYMENT.md`).

## Multi-tenancy model

- A `Client` row represents one MSP customer organisation.
- A `User` belongs to at most one `Client` (`users.client_id`, nullable for
  internal On IT staff) and has one `UserRole`
  (`super_admin`, `account_manager`, `client_admin`, `client_billing_admin`,
  `client_requester`; `client_user` is a deprecated alias kept only for
  legacy `portal_links.required_role` values).
- `account_manager` users can additionally be assigned to specific clients
  via the `client_user` pivot table (`assignedClients()` /
  `accessibleClientIds()`), for staff who manage a subset of customers
  without being full Super Admins.
- **`User::canAccessClient()` and `User::accessibleClientIds()` are the
  single source of truth for tenant scoping** and are used by every Policy
  and by admin index queries. See `docs/SECURITY.md` for the full
  authorization model.

## Data model (core tables)

- `clients` - one row per MSP customer; holds every integration's
  external ID (SuperOps account, Pax8 company, Entra tenant/group,
  Dropsuite/Huntress org) plus a `product_entitlements` JSON column
  (which sold products are enabled for this client) and an
  `onboarding_checklist` JSON column.
- `users` - portal users (staff and client-facing), `client_id` FK
  (nullable, `nullOnDelete`), unique `email`, `entra_object_id` (indexed,
  used as the primary SSO match key), `microsoft_tokens` (encrypted cast).
- `client_user` - pivot for Account Manager → assigned-clients.
- `client_notices` / `client_recommendations` / `client_opportunities` -
  per-client content admins publish to the customer dashboard.
- `portal_links` - configurable dashboard tiles/links, optionally scoped to
  a client and/or a minimum role.
- `activity_logs` - append-only audit trail of sensitive actions
  (`ActivityLogService::log()`), pruned by `model:prune` after
  `ACTIVITY_LOG_RETAIN_DAYS` (default 90).
- `settings` - simple key/value store for admin-tunable runtime settings
  (e.g. adaptive refresh cadence), edited via `Admin\SettingController`
  (Super Admin only).
- `client_metric_daily_snapshots` - nightly rollups used for "vs last
  month" comparisons on dashboards/reports.
- Standard Laravel tables: `sessions`, `cache`, `cache_locks`, `jobs`,
  `failed_jobs` (all database-backed - no Redis in this stack).

All foreign keys are declared with explicit `constrained()` +
`cascadeOnDelete()`/`nullOnDelete()`, and lookup/filter columns
(`is_active`, `client_id` combinations, `created_at`) are indexed. See
`docs/SYSTEM_AUDIT.md` → Database Review for the full migration audit.

## External integrations (`app/Services/*`)

Each integration follows the same shape: a low-level `*ApiClient` (HTTP
calls with an explicit timeout), a `*MetricsService`/`*Service` that adds
caching, entitlement checks and business logic, and (where the data feeds
the client dashboard) a `Feeds\*DashboardFeed` implementing
`App\Contracts\DashboardFeed`, registered in
`App\Services\Portal\DashboardFeedRegistry`.

| Integration | Purpose | Client entry point |
|---|---|---|
| Microsoft Graph / Entra ID | SSO, directory, group sync, SCIM provisioning | `app/Services/EntraSync/MicrosoftGraphClient.php` |
| SuperOps | PSA tickets, SSO launch, requester sync | `app/Services/SuperOps/SuperOpsApiClient.php` |
| Huntress | Security incidents/cases | `app/Services/Huntress/HuntressApiClient.php` |
| Dropsuite | Backup job metrics | `app/Services/Dropsuite/DropsuiteApiClient.php` |
| Pax8 | Billing/SSO launch | `app/Services/Pax8/Pax8SsoService.php` |

Whether a given integration is shown to a given client/user is decided by
`App\Services\Portal\ClientProductService`, which layers three checks:
**entitled** (sold to this client, `clients.product_entitlements`),
**mapped** (the client has the relevant external ID saved), and
**platform-ready** (the portal itself has credentials/config for that
platform, e.g. `ENTRA_SYNC_CLIENT_ID`). All three must pass for
`shouldRefresh()`/`shouldShowForViewer()` to return true - this is a
fail-closed design (missing config denies rather than errors).

## Background jobs & scheduling

No Redis, no Horizon, no message broker - `QUEUE_CONNECTION=database`.
Production runs queue workers and the scheduler as systemd timers (see
`docs/DEPLOYMENT.md`); `withoutOverlapping()` guards every scheduled
command, and slow-refresh jobs (Entra sync, SCIM apply/repair, directory
refresh) use `Cache::lock()` to prevent concurrent runs per client.

## Frontend

Vite 5 + Tailwind 3 + Alpine.js, single entry (`resources/css/app.css`,
`resources/js/app.js`), no framework/SPA. Compiled assets under
`public/build/` are **committed to the repository** (see
`docs/DEPLOYMENT.md`) so a production deploy does not require Node on the
server unless assets changed.

## Further reading

- `docs/SECURITY.md` - authentication/authorization model in depth
- `docs/DEPLOYMENT.md` - production deployment steps
- `docs/SYSTEM_AUDIT.md` - full audit findings
- `Brain/Architecture.md`, `Brain/DatabaseSchema.md` - the team's own,
  more implementation-detailed architecture notes
