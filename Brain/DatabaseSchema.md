# On IT Portal — Database Schema

## Core Tables

### clients

`id`, `name`, `slug`, `superops_account_id`, `superops_sso_enabled`, `pax8_company_id`, `pax8_sso_enabled`, `dropsuite_organization_id`, `huntress_organization_id`, `entra_tenant_id`, `entra_group_id`, `entra_superops_app_id` (customer SCIM **Application (client) ID** — Entra ID Free assignment), `entra_superops_sso_app_id` (customer Client SSO **Application (client) ID** — Entra ID Free requester login assignment), `entra_sync_enabled`, `entra_synced_at`, `onboarding_checklist` (JSON), `product_entitlements` (JSON: sold flags per product key `superops` / `m365` / `huntress` / `dropsuite` / `pax8`), `is_active`, timestamps

### users

`id`, `client_id`, `entra_object_id`, `superops_user_id`, `microsoft_tokens` (encrypted), `email`, `name`, `role`, `is_active`, `provisioned_by` (`manual` | `entra_sync`), `last_login_at`, `entra_synced_at`, `remember_token`, `superops_synced_at`, timestamps

### portal_links

`id`, `client_id`, `name`, `description`, `url`, `link_type`, `icon`, `required_role`, `display_order`, `is_active`, `open_in_new_tab`, timestamps

### client_notices, client_recommendations, client_opportunities

Client-scoped CMS tables with `is_active`, ordering, and scheduling fields on notices.

### pivot table `client_user`

Account manager ↔ client many-to-many pivot (not a user role).

### activity_logs, settings, sessions

Standard Laravel / audit tables.

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

## Tenant Rules

All client content filtered by `client_id`. SuperOps tickets filtered by requester identity in application layer.
