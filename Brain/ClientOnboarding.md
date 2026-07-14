# On IT Portal — Client Onboarding (Master Checklist)

Use this when onboarding a **new client organisation** or a **new user**. It lists every system and what to configure in each.

> **Start here in the app:** **Admin → Clients → Add Client** (form only), then **Edit** after Create — the **Client setup** guide on the **right** tracks progress. Backup text: [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md).

**Related docs (read once for platform setup):**

| Doc | When |
|---|---|
| [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) | **In-app wizard + backup checklist** |
| [ColleagueSetupGuide.md](ColleagueSetupGuide.md) | **Give this to staff adding clients/users (simple)** |
| [AccessAndSync.md](AccessAndSync.md) | **Auto-sync, offboarding, 60-tenant scale** |
| [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) | **Complete Entra/SCIM/SAML re-do from scratch** |
| [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) | One-time Global SSO + **per-client customer GA Accept** (50+ clients) |
| [OperatorRunbook.md](OperatorRunbook.md) | Phase A test, day-to-day ops |
| [Authentication.md](Authentication.md) | Portal OAuth (app #1) |
| [Deployment.md](Deployment.md) | Production Plesk |

---

## Who performs each step (MSP — ~100 clients)

Every step is performed by **On IT technicians**. The label shows **where** you work, not a different company.

| Label in app | Where | Examples |
|--------------|-------|----------|
| **On IT technician (portal / SuperOps)** | app.onit.ltd admin, SuperOps MSP console, Pax8 partner | Client record, Account ID, sync buttons |
| **On IT technician (customer Entra / GDAP)** | portal.azure.com in the **customer** tenant | Security group, SCIM app, Portal Graph consent (04), SuperOps SSO Accept (06) |

If you do not have GDAP on a tenant, send the customer admin the consent URLs from **checklist steps 04 and 06** — the checklist owner is still On IT until handed off.

Same playbook for every client: **Admin → Clients → Add → Edit** → work the checklist → next client.

---

| Page | What you see |
|------|----------------|
| **Add Client** | Form only — fill in details and click **Create** once |
| **Edit Client** (opens after Create) | Form on the left (**Update** saves fields) + setup checklist on the right |

The checklist does not appear until the client is saved. **Add Client** (form only) → **Create** → **Edit Client** opens with this guide on the right. There is no “create client” step in the checklist — you cannot see it without already having a client record.

### In-app instruction format (checklist right column)

Each step uses an **install-manual** layout (not a flat bullet list):

| Block | Meaning |
|-------|---------|
| **Important** | Critical notes (e.g. one Entra app for SCIM + SAML) |
| **Before you start** | Prerequisites — complete these first; they are **not** numbered steps |
| **Part A / B / C** | Numbered action steps with **Where:** line per section |
| **Check your work** | Verification at the end |

Full click-by-click text lives in the app; Brain docs are reference copies — keep step numbers aligned with the checklist below.

### Checklist steps (01–10, Edit page only)

| # | Step | Who |
|---|------|-----|
| 01 | Link SuperOps client | On IT technician (portal / SuperOps) |
| 02 | Link Pax8 company (optional) | On IT technician (portal / SuperOps) |
| 03 | M365 security group + Entra tenant | On IT technician (customer Entra / GDAP) |
| 04 | Portal Graph admin consent | On IT technician (customer Entra / GDAP) |
| 05 | SuperOps SCIM (requesters) | On IT technician (customer Entra / GDAP) |
| 06 | SuperOps requester SSO (Global SSO Accept) | On IT technician (customer Entra / GDAP) |
| 07 | Enable portal sync | On IT technician (portal / SuperOps) |
| 08 | Run portal sync | On IT technician (portal / SuperOps) |
| 09 | Test sign-in | On IT technician (portal / SuperOps) |
| 10 | Hand off to customer | On IT technician (portal / SuperOps) |

**Before step 01:** **Admin → Clients → Add Client** — name + optional SuperOps/Pax8 only → **Create client**. No Entra fields on that page. Slug is auto-generated from the name.

**Before step 03:** On **Edit Client**, set **Customer Entra license tier** (`Entra ID Free` or `Entra ID P1 or higher`) on the left. This changes steps 03, 05, and 07 instructions. **Every customer creates the security group** `On IT Portal - {Company}` in step 03 — on Free, SuperOps SCIM uses the SuperOps app user list (step 05), not group assignment in Azure.

### Auto vs manual checklist steps

| Step | Completes automatically when… | Manual tick only if… |
|------|------------------------------|----------------------|
| 01 SuperOps linked | `SuperOps Account ID` saved | — |
| 02 Pax8 linked (optional) | Pax8 off, or company ID saved | — |
| 03 M365 security group + Entra tenant | **Both** `Entra tenant ID` and `Entra group ID` saved | Done in Azure but IDs not pasted yet |
| 04 Portal Graph admin consent | Entra sync has run successfully | Consent granted but sync not run yet |
| 05 SuperOps SCIM | — | Done in SuperOps + customer Entra |
| 06 SuperOps requester SSO (Global SSO Accept) | — | Customer GA **Accepts** multitenant app **SuperOps Requester SSO (On IT)** + assign `On IT Portal - {Company}` in **customer** tenant. Not Client SSO. |
| 07 Enable portal sync | Tenant ID + group ID (+ app ID on Free) saved, **Entra sync enabled** ticked, **Save client** | — |
| 08 Run portal sync | `entra_synced_at` set after **Dry run sync** then **Sync now** on this page | — |
| 09 Test sign-in | — | You tested in incognito |
| 10 Hand off | — | Customer notified |

**Step 07 prerequisites** (`hasEntraSyncPrerequisites()` in code):

| License tier | Required on Edit (left column) |
|--------------|--------------------------------|
| **Entra ID P1** | Entra tenant ID + Entra group ID + Entra sync enabled |
| **Entra ID Free** | Above + **SuperOps Application (client) ID** |

**Rule:** Portal sync reads the **whole tenant**, **maintains** the security group when `entra_group_id` is set, **assigns users to the SuperOps app** on Entra ID Free when `entra_superops_app_id` is set, and **writes the full SuperOps last name** to `extensionAttribute1` (e.g. `(User Mailbox)`) — **not** M365 `displayName`. Portal user names in app.onit.ltd stay plain M365 names. Entra SCIM maps `name.familyName` **Direct** from `extensionAttribute1`. Provisioning must be **ON** in Entra. See [SuperOpsEntraSync.md](SuperOpsEntraSync.md#entra-scim-attribute-mapping-one-time-per-customer).

**Dry run sync** / **Sync now** are orange buttons on the left under Microsoft Entra sync on this Edit page. **Dry run** shows a preview message at the top. **Sync now** applies changes in the background — refresh for **Last synced**. Technicians work only in this portal, SuperOps, and customer M365 — never on the server.

---

## How the systems connect

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         NEW CLIENT / NEW USER                           │
└─────────────────────────────────────────────────────────────────────────┘
         │                    │                    │
         ▼                    ▼                    ▼
   ┌───────────┐      ┌───────────────┐    ┌─────────────────┐
   │ SuperOps  │      │ On IT Portal  │    │ Microsoft Entra │
   │ (MSP)     │      │ (Laravel)     │    │                 │
   └───────────┘      └───────────────┘    └─────────────────┘
         │                    │                    │
   Client +            Client record +      Group membership
   requester users     portal users         (Global SSO path only)
```

**Golden rule:** the user's **work email** must match exactly in all three places.

| System | Field | Example |
|---|---|---|
| SuperOps | Requester email | `jane@clientco.example` |
| Portal | `users.email` | `jane@clientco.example` |
| Entra | Group member UPN | `jane@clientco.example` |

---

## Part 0 — One-time platform setup (On IT — already done)

Do this **once** per environment. On IT production values are recorded here so you do not hunt for them again.

### 0.1 Portal OAuth (sign in to On IT Portal)

| Item | On IT value |
|---|---|
| App type | Entra app registration — OAuth / OIDC |
| Multi-tenant | `organizations` |
| Redirect URI (local) | `http://localhost:8000/auth/microsoft/callback` |
| Redirect URI (prod) | `https://{your-portal-domain}/auth/microsoft/callback` |
| `.env` keys | `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_REDIRECT_URI` |

See [Authentication.md](Authentication.md).

### 0.2 SuperOps Global SSO (sign in to SuperOps as requester)

| Item | On IT value |
|---|---|
| SuperOps subdomain | `onitltd` |
| Requester portal (custom) | https://portal.onit.ltd |
| Entra enterprise app name | **SuperOps Requester SSO (On IT)** |
| Application (client) ID | `bf1c303e-6015-43f7-abb2-5dfe8f67a5a1` |
| Object ID | `cc9c46a8-b038-4591-beab-414584233245` |
| On IT tenant ID | `586cc505-d298-4131-a3dc-9d1cd7c5ac0b` |
| Supported account types | **Multiple Entra ID tenants** / Allow all tenants (verified 2026-07-14) |
| Application ID URI (Expose an API) | `https://onit.ltd/superops-requester-sso` |

**SAML — Basic configuration (Entra Enterprise app → Single sign-on)**

| Field | Value |
|---|---|
| Identifier (Entity ID) | `https://clientuser.superops.ai` — SuperOps fixed value; **not** the Application ID URI |
| Reply URL (ACS) | `https://portal.onit.ltd/accounts-web/accounts/saml/response/5684471812792168448` |
| Sign on URL | *(leave empty)* |
| Logout URL | *(leave empty)* |

> **Do not confuse:** SuperOps Entity ID = `https://clientuser.superops.ai` on the SAML blade. Multitenant App ID URI = `https://onit.ltd/superops-requester-sso` under Expose an API. See [SuperOpsRequesterSsoSetup.md §2.1a](SuperOpsRequesterSsoSetup.md).

**SAML — Attributes & claims (Entra)** — must be lowercase:

| Claim name | Source attribute |
|---|---|
| `email` | `user.mail` |
| `firstname` | `user.givenname` |
| `lastname` | `user.surname` |

**SuperOps → Settings → Requester Login → SSO Protected → Global SSO**

| Field | Value |
|---|---|
| Consumer service URL | *(same as Reply URL above — read-only in SuperOps)* |
| IDP Login URL | `https://login.microsoftonline.com/586cc505-d298-4131-a3dc-9d1cd7c5ac0b/saml2` |
| Certificate | Entra SAML cert (Base64 body only, no BEGIN/END lines) |
| Global SSO | **ON** |

**Portal `.env`**

```env
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_SSO_ENABLED=true
SUPEROPS_REQUESTER_SSO_CLIENT_ID=bf1c303e-6015-43f7-abb2-5dfe8f67a5a1
# Leave SUPEROPS_SSO_URL empty — Entra Login URL belongs in SuperOps admin only (see below)
```

**Per client (checklist 06):** customer Global Admin Accepts this app — see [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) § Multitenant Accept.

**Entra Login URL** (`https://login.microsoftonline.com/586cc505-d298-4131-a3dc-9d1cd7c5ac0b/saml2`) — copy from **Entra → SuperOps Requester SSO app → Single sign-on → Login URL** (Section 4). Paste into **SuperOps Step 2 IDP Login URL only**. Click **Save**. See [SuperOpsRequesterSsoSetup.md §2.6–2.8](SuperOpsRequesterSsoSetup.md).

Full walkthrough: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).

### 0.3 Optional — SuperOps API (embedded `/support`)

```env
SUPEROPS_API_TOKEN=...
SUPEROPS_REGION=us
```

Without this, dashboard **SuperOps** SSO launch still works; `/support` embed does not.

### 0.4 On IT internal client (SSO testing)

| Item | Value |
|---|---|
| Portal client name | On IT Technology Partners |
| Slug | `on-it-technology-partners` |
| Test requester email | `portal.test@onit.ltd` |
| Seeder | `php artisan db:seed --class=OnItTechnologyPartnersSeeder` |

Matches SuperOps client **On IT Technology Partners** for Phase A testing.

---

## M365 / Entra user sync (built)

**Two independent syncs** from one M365 security group per customer. See [AccessAndSync.md](AccessAndSync.md).

| Sync | System | How |
|---|---|---|
| **1** | SuperOps requesters | SuperOps Entra SCIM per client — [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| **2** | Portal users | `portal:sync-entra-users` — [EntraGroupSync.md](EntraGroupSync.md) |

Manual **Admin → Users** remains available for pilots or exceptions (`provisioned_by = manual` users are not touched by sync).

**Offboarding:** remove from the security group (or disable M365). SCIM deprovisions SuperOps; portal sync sets `is_active=false`.

---

## Part 1 — New client organisation

> **Canonical flow:** **Admin → Clients → Edit** checklist steps **01–10**. Do **not** use SuperOps **Client SSO**.

Every real customer (own M365 tenant) uses the same model:

1. **Platform (once):** SuperOps **Global SSO** + Entra app **SuperOps Requester SSO (On IT)** (multitenant App Registration + SAML). See §0.2 and [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).
2. **Per client:** checklist **04** Portal Graph Accept + **06** SuperOps SSO Accept (customer Global Admin) + assign group `On IT Portal - {Company}` in the **customer** tenant.

| # | System | Action | Done |
|---|---|---|---|
| 1 | **SuperOps** | Create / confirm client; note Account ID | ☐ |
| 2 | **Portal** | **Admin → Clients** → Create → Edit checklist | ☐ |
| 3 | **Entra (customer)** | Group `On IT Portal - {Company}` + tenant ID (step 03) | ☐ |
| 4 | **Portal / customer GA** | Step 04 Portal Graph admin consent | ☐ |
| 5 | **SuperOps + customer Entra** | Step 05 SCIM on `SuperOps - {Company}` | ☐ |
| 6 | **Customer GA** | Step 06 Accept **SuperOps Requester SSO (On IT)** + assign Portal group | ☐ |
| 7–8 | **Portal** | Enable sync → Dry run → Sync now | ☐ |
| 9–10 | **Portal** | Test Microsoft sign-in → hand off | ☐ |

**Accept URL pattern (step 06):**

```
https://login.microsoftonline.com/{CUSTOMER-TENANT-ID}/adminconsent?client_id=bf1c303e-6015-43f7-abb2-5dfe8f67a5a1
```

Generated on Edit Client when Entra tenant ID is saved. Portal env: `SUPEROPS_REQUESTER_SSO_CLIENT_ID` (defaults to that Application ID).

**Symptom if step 06 was skipped:** Microsoft error that the account does not exist in tenant **On IT Technology Partners LTD** / cannot access `https://clientuser.superops.ai`.

---

## Part 2 — New user on an existing client

| # | System | Action | Done |
|---|---|---|---|
| 1 | **Portal / sync** | User appears after Sync now (or Admin → Users exception) | ☐ |
| 2 | **SuperOps** | Requester exists via SCIM (same email) | ☐ |
| 3 | **Entra (customer)** | In Portal group / assigned to SuperOps Requester SSO SP after Accept | ☐ |
| 4 | **Test** | Portal login → SuperOps tile → requester | ☐ |

**Not required per user:** changing SAML Reply URL, certificate, Entity ID, or SuperOps Global SSO fields.

---

## Part 3 — Do not use SuperOps Client SSO

On IT operates **Global SSO only** for all 50+ clients. SuperOps **Client SSO** (`+ Configuration` per client) is **out of scope**.

Per-client work is checklist **06**: customer Global Admin **Accept** of the multitenant app **SuperOps Requester SSO (On IT)** + assign `On IT Portal - {Company}` in the **customer** tenant. Full detail: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).

---

## Part 4 — Verification tests

### After platform setup (once)

| Test | Steps | Pass |
|---|---|---|
| SAML / Global SSO panel | SuperOps Global SSO ON; IDP URL + cert filled | ☐ |
| Portal → SuperOps (On IT test) | `portal.test@onit.ltd` → dashboard → SuperOps → requester | ☐ |
| Portal OAuth | Test user → `/login` → Microsoft | ☐ |
| Pax8 | Dashboard → **Pax8** → launch (if used) | ☐ |

### After each new client

| Test | Pass |
|---|---|
| Step 06 Accept completed for that tenant | ☐ |
| Portal login with customer work email | ☐ |
| Dashboard shows SuperOps (+ Pax8 if enabled) | ☐ |
| SuperOps opens as **requester** (not technician) | ☐ |
| No “account does not exist in On IT tenant” at Microsoft | ☐ |
---

## Part 5 — What NOT to do

| Don't | Why |
|---|---|
| Test requester SSO as `tom.ashby@onit.ltd` | SuperOps maps Tom to **technician** — misleading results |
| Assign technicians to Requester SSO app | Technician ≠ requester |
| Reuse portal OAuth app for SuperOps SAML | Wrong protocol and URLs |
| Open SuperOps **Client SSO** for a normal client | On IT uses **Global SSO + per-tenant Accept** only |
| Skip step 06 Accept for a new customer tenant | Microsoft: user not in On IT tenant / cannot access clientuser.superops.ai |
| Confuse step 04 with step 06 | 04 = Portal Graph; 06 = SuperOps Requester SSO |
| Change Reply URL / cert without updating both ends | SAML breaks for **every** client on Global SSO |

---

## Part 6 — Phase A test status

On IT validated Global SSO with **`portal.test@onit.ltd`** (`client_user` on On IT Technology Partners):

| Step | Status |
|---|---|
| Entra claims `email` (`user.userprincipalname`), `firstname`, `lastname` | ✅ |
| Group `SuperOps Test Requesters` → SAML app | ✅ |
| `portal.test@onit.ltd` in SuperOps as requester | ✅ |
| `portal.test@onit.ltd` in Portal Admin → Users | ✅ (seeder) |
| Portal → SuperOps → `/#/requester/login` → Microsoft → requester dashboard | ✅ |

**Launch path:** `SUPEROPS_REQUESTER_LOGIN_PATH=/#/requester/login` — **not** `/#/login/requester` (invalid; shows role chooser).

**Do not test requester SSO as `tom.ashby@onit.ltd`** — MSP technician in SuperOps. Portal blocks `super_admin` from SuperOps launch.

**Next:** For each real customer tenant, complete checklist **06 Accept** (not Client SSO). `portal.onit.ltd` remains SuperOps only.

See [OperatorRunbook.md](OperatorRunbook.md) Phase A for click-by-click.

---

## Quick copy — new client worksheet

```
Client name:     _______________________
Portal slug:     _______________________
Entra tenant ID: _______________________

SuperOps account ID:  _______________________
Step 04 Portal Graph Accept: [ ] Done
Step 06 SuperOps SSO Accept: [ ] Done  (app bf1c303e-6015-43f7-abb2-5dfe8f67a5a1)
Group On IT Portal - _______________ assigned in customer tenant: [ ]

Tested by: __________  Date: __________
```

---

## Change log

| Date | Change |
|---|---|
| 2026-07-14 | Multitenant verified (App ID URI `onit.ltd/…` vs SAML Entity ID `clientuser.superops.ai`); step 06 Accept path; Client SSO removed from operating model |
| 2026-06-15 | Phase A SSO validated; launch path `/#/requester/login`; Plesk deployment notes |
