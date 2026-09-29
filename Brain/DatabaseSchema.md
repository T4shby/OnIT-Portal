# On IT Portal - Database Schema

## Core Tables

### clients

`id`, `name`, `slug`, `superops_account_id`, `superops_sso_enabled`, `pax8_company_id`, `pax8_sso_enabled`, `dropsuite_organization_id`, `huntress_organization_id`, `entra_tenant_id`, `entra_group_id`, `entra_superops_app_id` (customer SCIM **Application (client) ID** - Entra ID Free assignment), `entra_superops_sso_app_id` (customer Client SSO **Application (client) ID** - Entra ID Free requester login assignment), `entra_sync_enabled`, `entra_synced_at`, `onboarding_checklist` (JSON), `product_entitlements` (JSON: entitled flags per catalog key - services `superops`/`m365`/`huntress`/`dropsuite` + licence vendors e.g. `pax8`; planned `usecure` when shipped), `is_active`, timestamps

**Planned (not migrated):** `usecure_company_id` - see [UsecureIntegration.md](UsecureIntegration.md).

### users

`id`, `client_id`, `entra_object_id`, `superops_user_id`, `microsoft_tokens` (encrypted), `email`, `name`, `role`, `is_active`, `provisioned_by` (`manual` | `entra_sync`), `last_login_at`, `entra_synced_at`, `remember_token`, `superops_synced_at`, timestamps

### portal_links

`id`, `client_id`, `name`, `description`, `url`, `link_type`, `icon`, `required_role`, `display_order`, `is_active`, `open_in_new_tab`, timestamps

### client_notices, client_recommendations, client_opportunities (retired 2026-09-29)

Client-scoped CMS tables with `is_active`, ordering, and scheduling fields on notices.

**Retired 2026-09-29:** dropped by the `2026_09_29_1200*_drop_*` migrations, along with their models, admin CRUD pages and the admin dashboard's "Active Notices" tile. No customer-facing view ever read them, so the repo owner chose to retire them rather than build one (F9 in `docs/SYSTEM_AUDIT.md`).

### pivot table `client_user`

Account manager ↔ client many-to-many pivot (not a user role).

### activity_logs, settings, sessions

Audit table for Admin → Activity Logs (logins, SSO launch, role changes, etc.).

| Rule | Detail |
|------|--------|
| Retention | Keep **90 days** (`ACTIVITY_LOG_RETAIN_DAYS`, default 90). Nightly `model:prune` at **03:20** in `APP_TIMEZONE` (production is UTC unless `.env` says otherwise). |
| Loopback IPs | **Do not skip** `127.0.0.1` / `::1`. On Plesk, nginx often makes PHP see localhost even for real users. Skipping those rows would drop most of the audit trail. `ActivityLogService` prefers `X-Real-IP` / `X-Forwarded-For` when `Request::ip()` is loopback, but still writes the row if that is all we have. |
| Size | ~6k rows was ~2.2 MB (2026-09-07). Not a disk risk; prune is a cap so it cannot grow forever. |

Standard Laravel settings / sessions tables.

### client_metric_daily_snapshots

Nightly (or on-demand) dashboard metric snapshots per client for **last-month compare** on glance home + Reports.

| Column | Notes |
|--------|--------|
| `client_id` | FK → clients, cascade delete |
| `snapshot_date` | Date (unique with client_id) |
| `payload` | JSON: `overall_band`, `value` (threats/resolved/sla), `services.{key}.metrics` map |

Command: `portal:capture-metric-snapshots` (scheduled **02:15** daily).

## Migrations

- `0001_01_01_000000_create_users_table.php`
- `0001_01_01_000001_create_portal_tables.php`
- `2026_06_12_120000_add_superops_integration.php`
- `2026_06_12_130600_add_remember_token_to_users_table.php`
- `2026_06_16_100000_add_entra_group_sync.php`
- `2026_06_19_100000_add_client_onboarding_checklist.php`
- `2026_06_25_100000_add_entra_superops_app_id_to_clients.php`
- `2026_07_14_140000_add_entra_superops_sso_app_id_to_clients.php`
- `2026_07_15_140000_add_dropsuite_organization_id_to_clients.php`
- `2026_07_15_141000_add_huntress_organization_id_to_clients.php`
- `2026_08_06_120000_add_product_entitlements_to_clients.php`
- `2026_08_10_120000_create_client_metric_daily_snapshots_table.php`

## Tenant Rules

All client content filtered by `client_id`. SuperOps tickets filtered by requester identity in application layer.
