# On IT Portal — Pax8 Integration

**Status:** Implemented (launch route). Pax8 API remains Phase 3.

## Current behaviour (problem)

| User state | Click Pax8 on dashboard | Result |
|---|---|---|
| Logged into On IT Portal | Opens `PAX8_PORTAL_URL` (default `https://app.pax8.com`) | Often works if browser already has Microsoft session |
| Not logged into On IT Portal | N/A — dashboard requires auth | N/A |
| Click Pax8 without Pax8 session | Plain link to Pax8 login | User must sign in again manually — **no `login_hint`, no role routing** |

Pax8 is a **static external URL** (`PortalLinkType::External`). There is **no** `/integrations/pax8/launch` route (unlike SuperOps).

See [PortalLinks.md](PortalLinks.md), [Decisions.md](Decisions.md) ADR-011.

## Target behaviour

| User type | Portal role | Pax8 destination |
|---|---|---|
| On IT technicians | `super_admin`, `account_manager` | **Partner portal** — buy/manage licenses (`app.pax8.com`) |
| Customer approvers | `client_admin`, `client_user` | **Company view** — their org's subscriptions/billing (once `pax8_company_id` linked on client) |

Both should launch via an **authenticated portal route** that:

1. Requires On IT Portal login (Microsoft OAuth already done)
2. Redirects to Pax8 with `login_hint={email}` (same pattern as [SuperOpsSsoService](../app/Services/SuperOps/SuperOpsSsoService.php))
3. Relies on **Pax8 Enterprise SSO (Azure AD)** configured in Pax8 Admin — not portal SAML

## Pax8 platform constraints (external docs)

- **Partner Enterprise SSO:** Azure AD federation at Pax8 → Admin → My Partner Profile → Enterprise SSO. Users must exist as **app users** in Pax8 **and** in Entra. [Pax8 Enterprise SSO PDF](https://www.pax8nebula.com/m/10eadb52f582df44/original/Enterprise-SSO.pdf)
- **Customer SSO:** Pax8 doc Q&A — *"Can my self-service customers take advantage of SSO? Not at this time."* Full customer IdP SSO is **not** available yet.
- **API:** OAuth 2.0 for automation ([devx.pax8.com](https://devx.pax8.com/docs/authentication)) — Phase 3, not launch SSO.

**Implication:** True one-click SSO for **customers** may be limited to `login_hint` + shared Microsoft session until Pax8 ships customer SSO. **Technicians** are the primary win if On IT has Pax8 Enterprise SSO enabled.

## Architecture (proposed)

```
Microsoft Entra → On IT Portal session
                  └── GET /integrations/pax8/launch
                        ├── Team member → PAX8_PARTNER_PORTAL_URL + login_hint
                        └── Client user  → company URL (client.pax8_company_id) + login_hint
```

Mirror [SuperOpsIntegration.md](SuperOpsIntegration.md) Pillar 2.

### New code

| Piece | Purpose |
|---|---|
| `PortalLinkType::Pax8Sso` | Internal launch route |
| `Pax8SsoService` | Role-based URL, access checks, `login_hint` |
| `Pax8LaunchController` | Auth middleware, redirect away |
| `clients.pax8_company_id` | Link portal client → Pax8 company UUID |
| Admin client form field | Store Pax8 company ID |
| `Brain/Pax8Integration.md` | This doc |

### Environment

```env
PAX8_PARTNER_PORTAL_URL=https://app.pax8.com
PAX8_COMPANY_URL_TEMPLATE=https://app.pax8.com/companies/{companyId}
PAX8_LOGIN_HINT_ENABLED=true
PAX8_SSO_ENABLED=true
```

Validate company URL template against live Pax8 UI when implementing.

## On IT one-time setup (operator)

1. Pax8 → **Admin → My Partner Profile → Enterprise SSO → Azure AD** (Global Admin consents)
2. Ensure technicians (`tom.ashby@onit.ltd`, `kris@onit.ltd`, …) are **Pax8 app users**
3. Per customer client in portal: set **Pax8 company ID** from Pax8 → Companies
4. For approvers: create as Pax8 users with company-scoped access (or wait for Pax8 customer SSO)

## Testing checklist

- [ ] Technician logged into portal → Pax8 → Microsoft (if needed) → partner dashboard
- [ ] Client approver with `pax8_company_id` → lands on company view
- [ ] Client without `pax8_company_id` → clear error on dashboard, not silent wrong page
- [ ] `client_user` cannot access partner-only admin paths

## Related

- [Roadmap.md](Roadmap.md) Phase 3 — Pax8 API
- [SuperOpsIntegration.md](SuperOpsIntegration.md) — launch pattern to copy
- [AccessAndSync.md](AccessAndSync.md) — identity model
