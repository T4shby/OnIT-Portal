# On IT Portal — Database Schema

## Core Tables

### clients

`id`, `name`, `slug`, `superops_account_id`, `superops_sso_enabled`, `entra_tenant_id`, `entra_group_id`, `entra_sync_enabled`, `entra_synced_at`, `is_active`, timestamps

### users

`id`, `client_id`, `entra_object_id`, `superops_user_id`, `microsoft_tokens` (encrypted), `email`, `name`, `role`, `is_active`, `provisioned_by` (`manual` | `entra_sync`), `last_login_at`, `entra_synced_at`, `remember_token`, `superops_synced_at`, timestamps

### portal_links

`id`, `client_id`, `name`, `description`, `url`, `link_type`, `icon`, `required_role`, `display_order`, `is_active`, `open_in_new_tab`, timestamps

### client_notices, client_recommendations, client_opportunities

Client-scoped CMS tables with `is_active`, ordering, and scheduling fields on notices.

### client_user

Account manager ↔ client pivot.

### activity_logs, settings, sessions

Standard Laravel / audit tables.

## Migrations

- `0001_01_01_000000_create_users_table.php`
- `0001_01_01_000001_create_portal_tables.php`
- `2026_06_12_120000_add_superops_integration.php`
- `2026_06_12_130600_add_remember_token_to_users_table.php`
- `2026_06_16_100000_add_entra_group_sync.php`

## Tenant Rules

All client content filtered by `client_id`. SuperOps tickets filtered by requester identity in application layer.
