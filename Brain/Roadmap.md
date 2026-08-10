# On IT Portal — Roadmap

Status as of **2026-08-10**. Shipped work is live product; backlog is ordered by real ops value.

## Shipped (do not re-plan as open work)

| Area | What exists |
|------|-------------|
| Auth | Multi-tenant Entra OIDC; pre-provision + optional Entra group sync |
| Roles | `super_admin`, `account_manager`, `client_admin`, `client_requester`, `client_billing_admin` |
| Organisation vs My Systems | Client Admin: org-wide System health; requesters/billing: personal **My Systems** only |
| SuperOps | Dashboard metrics, ticket list/create API, SSO launch; **threads/comments live in SuperOps** (not portal-embedded conversation) |
| Huntress | Org security tile + cases; staff board; Integration Health column |
| Dropsuite | Org backup summary (24h success / open issues); full Online backups inventory for Client Admin; IH column |
| M365 | Async directory + licence insights; free/trial SKUs excluded from paid util |
| Product entitlements | Sold vs mapped; tiles / prewarm / IH honour entitlements |
| Technician Integration Health | Live pipeline, cold (Never loaded), stuck/due/aging; **reads current feed `cacheKey()` only** |
| Staff Admin dashboard | Clients / users / notices stats + **Sold product coverage** KPI matrix |
| Onboarding | Technician checklist / GDAP; Client SSO per customer tenant (not Global SSO experiment) |
| Deploy | GitHub main → Plesk mirror → live; see [Deployment.md](Deployment.md) |

Ops KPI for sold service feeds: **cold cells → 0**. Prewarm still queues **cold optional** feeds when the jobs table is deep (≥40); warm optional refresh is skipped under pressure.

## Near-term backlog (next value)

1. **Finish / accept client UI overhaul** — branch `UIOverhaul`: glance home + Reports; merge when signed off — [UIOverhaul.md](UIOverhaul.md).
2. **Portfolio reporting polish** — export / AM digest of sold coverage matrix; filter by AM assignment (admin dashboard cards already land base KPI).
3. **Pax8 deeper** — billed catalogue / usage beyond SSO launch tile (partner API).
4. **Notification hooks** — email or Teams when IH severity stays warning (cold / stuck) beyond threshold.
5. **Client-facing branding** — per-tenant logo / colour when multiportal presentation needs it.
6. **Performance budget** — queue round-robin fairness if partner count grows past current prewarm model.
7. **UI pipeline metrics** — Secure Score, MFA coverage, MoM history, activity timeline (called out on new home) — [UIOverhaul.md](UIOverhaul.md).

## Later / optional

- **usecure human-risk feed** — full design parked for later; **not** near-term work. Unblocks when On IT has beta GraphQL API keys from usecure Support (ops request in own time). Build plan: [UsecureIntegration.md](UsecureIntegration.md).
- PWA / mobile polish
- Public API for AM tools
- Marketplace-style third-party tiles
- Self-service user invites (only if product deliberately leaves pre-provision model)

## Explicitly deferred / not planned

| Idea | Why not now |
|------|-------------|
| Full ticket thread + comments UI in portal | Replies stay in SuperOps; portal is list/create + SSO (ADR-022) |
| Global SuperOps Multitenant SSO experiment | Retired; **Client SSO per customer** only |
| Email-blast “AM reports” as primary surface | Prefer in-app coverage matrix + IH (ADR-023) |
| Hardcoding every free M365 SKU string | Heuristics + paid seat util labels only |

## Doc map

| Want to… | Read |
|----------|------|
| How CA / feeds / IH work | [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| usecure (planned) | [UsecureIntegration.md](UsecureIntegration.md) |
| Auth | [Authentication.md](Authentication.md) |
| Support + SuperOps | [SuperOpsIntegration.md](SuperOpsIntegration.md) |
| Requirements / stories | [ProductRequirements.md](ProductRequirements.md) |
| ADRs | [Decisions.md](Decisions.md) |
