# On IT Portal — Product Requirements

Living requirements as of **2026-08-10**. MVP shipped; this file tracks **current product truth** plus remaining stories.

## Product intent

MSP-side multi-tenant portal: one Microsoft login for On IT customers, organisation and personal service health snapshots (SuperOps / M365 / Huntress / Dropsuite), support list + SuperOps SSO for full PSAs, technician ops (onboarding, Integration Health, coverage KPI).

## Roles

| Role | Scope |
|------|--------|
| `super_admin` | All clients; settings; timing; full admin |
| `account_manager` | Assigned clients; Integration Health + sold coverage for those clients |
| `client_admin` | Own org: **Organisation** overview, all people/systems for that customer |
| `client_requester` / `client_billing_admin` | Own org: **My Systems** personal tiles; tickets they own; no Organisation nav |

## In scope (shipped)

| Area | Requirement |
|------|-------------|
| Auth | Multi-tenant Entra; pre-provisioned users; capability gates |
| SuperOps support | List + create tickets in portal; **open SuperOps for conversation** (not embed full thread UI) |
| SuperOps metrics | Organisation (CA) / personal requester views from cached GraphQL metrics |
| M365 | Directory page + licence insights tiles; async refresh |
| Huntress | Sold org security posture + cases (visibility rules) |
| Dropsuite | Org backup health summary + CA full inventory page |
| Entitlements | Sold ↔ map IDs; not-sold upsell for CA; hide for requesters |
| Integration Health | Per-feed health using each service’s public `cacheKey()` |
| Prewarm | Critical always; cold optional queues under deep queue; warm optional may skip |
| Staff reporting | Sold product coverage live/setup/cold/failed on admin dashboard |
| Onboarding | Technician-driven Entra / SCIM / Client SSO (see onboarding Brain docs) |

## Out of scope (current)

- Full bi-directional SuperOps ticket sync (portal as system of record for comments)
- Customer self-registration without pre-provision
- Per-client free SKU encyclopaedias
- Global SuperOps SSO multitenant admin-consent product model

## User stories

### Client Admin

- See sold systems for my organisation (health tiles, layout scales with tile count).
- See not-sold products with contact AM message when applicable.
- Open SuperOps for ticket conversation depth.
- Open Online backups list (Dropsuite) when entitled/mapped.

### Requester / Billing

- See **My Systems** only (personal live products).
- Create and list my tickets; open SuperOps for discussion.
- No cross-organisation or colleague org-wide data.

### Account Manager / Super Admin

- Integration Health: fix cold, stuck, mapping.
- Admin dashboard: portfolio **sold → live** coverage % and cold count.
- Onboard customers end-to-end without handing Azure tasks to the customer.

## Functional requirements (selected)

### Auth (FR-AUTH)

Unchanged intent: multi-tenant Entra, pre-provision, inactive deny, SuperOps user link when API present.

### SuperOps tickets (FR-SOPS)

- FR-SOPS-01: List/create tickets scoped by requester + client SuperOps account.
- FR-SOPS-02: SSO launch to full SuperOps portal (requester vs technician paths).
- FR-SOPS-03: Show opening description on ticket detail; **no requirement** to render comments/attachments in portal — CTA to SuperOps (ADR-022).

### Organisation / systems (FR-ORG)

- FR-ORG-01: Client Admin Overview = org-wide products; gates `view-organisation-wide`.
- FR-ORG-02: Requester My Systems = personal visibility; gate `view-my-systems`.
- FR-ORG-03: Feeds modular via `DashboardFeed` + `ClientProductService` entitlements.
- FR-ORG-04: Sold products should obtain a first cache snapshot (cold → 0) via prewarm/workers.

### Technician ops (FR-OPS)

- FR-OPS-01: Integration Health cells must read **only** the current feed `cacheKey()` (no legacy key fallbacks).
- FR-OPS-02: Prewarm queues cold optional feeds even when queue depth skips warm optional.
- FR-OPS-03: Admin dashboard exposes productCoverage sold/live/setup/cold/failed.

### Admin CMS (FR-ADMIN)

Clients, users, links, content, settings, activity logs — as today.

## Non-functional

- NFR-01: Strict tenant isolation (`client_id` + policies).
- NFR-02: Dashboard loads from cache; never block HTTP on full partner API fan-out.
- NFR-03: Production DB sessions; deploy via Plesk mirror pipeline.
- NFR-04: No secrets in repo; partner tokens in `.env`.

## Backlog stories

See [Roadmap.md](Roadmap.md) Near-term backlog.
