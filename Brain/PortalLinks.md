# On IT Portal — Portal Links

See [SuperOpsIntegration.md](SuperOpsIntegration.md) for SuperOps detail.

## Link Types

| Type | Behaviour |
|---|---|
| `external` | Opens URL (new tab optional) |
| `superops_embedded` | `/support` — embedded tickets |
| `superops_sso` | SSO launch to full SuperOps portal |

## Resolution

`PortalLink::resolved_url` returns internal routes for SuperOps types, stored `url` for external.

`ExternalServicesService::getLinksForUser()` filters by client, role, active status. Cached 5 min per client.

## Admin

- `/admin/portal-links` — link type selector, `required_role`, client scope
- SuperOps types auto-set URL via `PortalLink::urlForType()`

## Default Dashboard Links (MVP)

The seeded global links are intentionally minimal — two portals only:

| Name | Type | Target |
|---|---|---|
| SuperOps | `superops_sso` | `/integrations/superops/launch` → SuperOps requester portal |
| Pax8 | `external` | `PAX8_PORTAL_URL` (default `https://app.pax8.com`) |

`ExternalServicesService::syncDefaultLinks()` upserts these and removes stale global links. Re-run with:

```powershell
php artisan db:seed --class=PortalLinkSeeder
```

Additional links (M365, KB, Billing, embedded support) can be added per client in Admin → Portal Links.

## Deduplication

`getLinksForUser()` deduplicates by `link_type` so duplicate seed runs or overlapping global/client rows do not show twice on the dashboard.
