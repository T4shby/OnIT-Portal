# On IT Portal - Architecture

Laravel 11 monolith: Blade + Tailwind + Alpine.js. Deployed to Plesk on Ubuntu.

## Layers

```
Presentation (Blade)
HTTP (Controllers, Middleware)
Authorization (Policies, Gates)
Services (ExternalServices, ActivityLog, SuperOps/*)
Data (Eloquent)
Database (MySQL / SQLite local)
```

## Key Flows

| Flow | Route | Service |
|---|---|---|
| Dashboard | `GET /dashboard` | `ExternalServicesService` |
| Support | `GET /support` | `SuperOpsTicketService` |
| SSO launch | `GET /integrations/superops/launch` | `SuperOpsSsoService::launchUrlFor()` - redirects to `/#/requester/login` + `login_hint` |
| Entra callback | `GET /auth/microsoft/callback` | Socialite + user sync |

## Directory

```
app/
  Enums/PortalLinkType.php, UserRole.php
  Http/Controllers/Auth, Support, Integrations, Admin
  Services/SuperOps/
  Models/
Brain/          ← source of truth
```

## Multi-Tenancy

`client_id` FK + policies + `User::canAccessClient()`.

## Audit log

Admin → Activity Logs (`activity_logs`). Written by `ActivityLogService` (login, SSO launch, role changes, CMS, etc.). Retain **90 days** then `model:prune` (scheduled 03:20). Do not drop localhost IPs. Details: [DatabaseSchema.md](DatabaseSchema.md), ADR-010 in [Decisions.md](Decisions.md).

## Caching

`portal_links.client.{id}` - 5 minutes.

## External Services

| Service | Level |
|---|---|
| SuperOps | Integrated |
| Pax8, M365, KB, Billing | Launch links |

See [SuperOpsIntegration.md](SuperOpsIntegration.md), [LocalDevelopment.md](LocalDevelopment.md), [Deployment.md](Deployment.md).
