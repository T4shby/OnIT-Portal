# Customer Portal SSO — Setup Guide (On IT)

How **client users** (`client_user`, `client_admin`) sign in to the On IT Portal and reach **SuperOps requester** and **Pax8 company** views with Microsoft — without technician access.

**Technician SSO** (done separately): [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md) · [Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md)

---

## What customers get

```
Customer work email
  → https://app.onit.ltd/login (Microsoft OAuth — Entra app #1)
  → Dashboard
  → SuperOps tile → portal.onit.ltd/#/requester/login → Microsoft SAML → requester console
  → Pax8 tile (optional) → app.pax8.com/companies/{id} → company subscriptions (sell price)
```

| Layer | SSO type | Who configures |
|---|---|---|
| **On IT Portal** | OAuth / OIDC (multi-tenant) | Already done — [Authentication.md](Authentication.md) |
| **SuperOps requester** | SAML (Entra app #2 or per-client Client SSO) | On IT + customer M365 admin |
| **Pax8 company view** | **No Microsoft SSO** from Pax8 for customers ([Pax8 FAQ](https://www.pax8nebula.com/m/10eadb52f582df44/original/Enterprise-SSO.pdf)) | Pax8 company users + portal launch only |

---

## Choose your path

| Path | Customer M365 | SuperOps SSO | Guide |
|---|---|---|---|
| **A — Test / On IT tenant** | Users in **On IT** Entra (`@onit.ltd`) | **Global SSO** (one Entra SAML app) | [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) |
| **B — Real customer** | **Customer's own** tenant | **Client SSO** per customer in SuperOps | Part 3 below + [ClientOnboarding.md](ClientOnboarding.md) |

Most production clients are **Path B**.

---

## Master checklist — customer SSO (SuperOps)

### Portal (once per environment)

- [ ] `MICROSOFT_CLIENT_ID` / secret / redirect on `app.onit.ltd`
- [ ] `SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd`
- [ ] `SUPEROPS_REQUESTER_LOGIN_PATH="/#/requester/login"`
- [ ] Deploy + `php artisan config:clear` + `optimize`

### Path A — Global SSO (On IT tenant test users)

- [ ] Entra app **SuperOps Requester SSO (On IT)** — SAML
- [ ] SuperOps **Requester Login → Global SSO** saved (IDP URL + cert)
- [ ] Group `SuperOps Test Requesters` assigned to SAML app
- [ ] Test user `portal.test@onit.ltd` in SuperOps + Portal + group

Detail: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md)

### Path B — Client SSO (customer's own M365)

Per customer:

- [ ] SuperOps → **Requester Login → SSO Protected → Client SSO** → + Configuration
- [ ] Copy **client-specific** Entity ID + Consumer Service URL
- [ ] **Customer Entra** → new enterprise app (non-gallery) → SAML with SuperOps values
- [ ] Claims: `email`, `firstname`, `lastname` (lowercase, empty namespace)
- [ ] Customer IT assigns their users/groups to **their** SAML app
- [ ] SuperOps links configuration to this client
- [ ] Portal: **Admin → Clients** → SuperOps Account ID + **SuperOps SSO enabled** ✓
- [ ] Portal: **Admin → Users** → client users with **work emails**

Detail: [ClientOnboarding.md](ClientOnboarding.md) Part 3

### Per customer — portal record

- [ ] **Admin → Clients → Edit**
  - SuperOps Account ID
  - **SuperOps SSO enabled** ✓
  - Pax8 Company ID (optional — for licensing tile)
  - **Pax8 access enabled** ✓ (if using Pax8)
- [ ] **Admin → Users** — each person with matching work email

### Test (private window)

- [ ] `app.onit.ltd/login` → customer's work email → Microsoft
- [ ] Dashboard loads with customer name
- [ ] **SuperOps** → Microsoft → **requester** dashboard (`/#/client-home`) — not technician chooser
- [ ] **Pax8** (if enabled) → company subscriptions view

**Do not test as `tom.ashby@onit.ltd`** — SuperOps maps Tom to technician.

---

## Pax8 for customers (licensing view)

Pax8 **Enterprise SSO is partner-only** (`onit.ltd`). Customers do **not** get the same Microsoft SSO into Pax8.

What we provide:

1. Portal **Pax8** tile when `pax8_sso_enabled` + `pax8_company_id` set on client
2. Launch: `https://app.pax8.com/companies/{id}?login_hint=email`
3. Customer must exist as **Pax8 company user** (partner creates in Pax8)

Customers see **sell price** (what you charge), not partner cost.

Detail: [Pax8CustomerAccess.md](Pax8CustomerAccess.md)

---

## Portal code behaviour

| User role | SuperOps launch | Pax8 launch |
|---|---|---|
| `client_user`, `client_admin` | `/#/requester/login?login_hint=…` if `superops_sso_enabled` | `/companies/{id}?login_hint=…` if `pax8_sso_enabled` + company ID |
| `super_admin`, `account_manager` | `/#/technician/login?login_hint=…` | `app.pax8.com/login?login_hint=…` |

Routes: `/integrations/superops/launch`, `/integrations/pax8/launch`

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| SuperOps role chooser | Launch path must be `/#/requester/login` (quoted in `.env`) |
| Tom lands as technician | Use customer test account only |
| SuperOps Error 1027 | Fix Entra SAML claims — [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) |
| Client on own M365, Global SSO fails | Use **Client SSO** (Path B), not On IT Global SSO app |
| Pax8 tile missing | Enable **Pax8 access** + set company ID on client |
| Pax8 asks for password | Expected — create Pax8 company user; no customer Entra SSO yet |
| Portal login fails | Check user exists in Admin → Users with correct email |

---

## Related

- [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md)
- [Pax8CustomerAccess.md](Pax8CustomerAccess.md)
- [NewClientSetupGuide.md](NewClientSetupGuide.md)
- [OperatorRunbook.md](OperatorRunbook.md)

---

## Change log

| Date | Notes |
|---|---|
| 2026-06-23 | Initial customer portal SSO guide; `pax8_sso_enabled` on clients |
