# On IT Portal — Client Onboarding (Master Checklist)

Use this when onboarding a **new client organisation** or a **new user**. It lists every system and what to configure in each.

> **Technician start here:** [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) and the live **Admin → Clients → Edit** guide.
>
> This file is the **reference master** (platform values, verification, anti-patterns). Click-by-click text lives in the app.

**Related docs:**

| Doc | When |
|---|---|
| [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) | **Only technician entry point** |
| [ColleagueSetupGuide.md](ColleagueSetupGuide.md) | Non-technical staff adding basic client records |
| [AccessAndSync.md](AccessAndSync.md) | Two-sync mental model |
| [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) | Complete Entra/SCIM re-do |
| [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) | Platform Global SSO + Accept recovery |
| [Authentication.md](Authentication.md) | Portal OAuth |
| [Deployment.md](Deployment.md) | Plesk (Tom only) |

---

## Who performs each step (MSP — ~100 clients)

Every step is performed by **On IT MSP technicians on the customer’s behalf**. The label shows **where** you work, not a different company. The customer does not receive setup links, sign in, or complete checklist tasks.

| Label in app | Where | Examples |
|--------------|-------|----------|
| **On IT technician (portal / SuperOps)** | app.onit.ltd admin, SuperOps MSP console, Pax8 partner | Client record, Account ID, sync buttons |
| **On IT technician (customer Entra / GDAP)** | portal.azure.com in the **customer** tenant | Security group, SCIM app, Portal Graph Accept (04), SuperOps SSO Accept (08) |

If On IT does not have the required delegated / GDAP role, stop and escalate internally until access is corrected. Do **not** hand setup actions or Accept URLs to the customer.

Same playbook for every client: **Admin → Clients → Add → Edit** → work the checklist → next client.

---

| Page | What you see |
|------|----------------|
| **Add Client** | Form only — fill in details and click **Create** once |
| **Edit Client** (opens after Create) | Form on the left (**Update** saves fields) + setup checklist on the right |

The checklist does not appear until the client is saved. **Add Client** (form only) → **Create** → **Edit Client** opens with this guide on the right. There is no “create client” step in the checklist — you cannot see it without already having a client record.

### In-app instruction format (checklist right column)

| Block | Meaning |
|-------|---------|
| **Start here** / orange Accept button | Generated action first when needed |
| **Do this** | Numbered clicks only |
| **Done when** | How you know the step finished |
| **Remember** | One short warning max |

Full click-by-click text lives in the app. Mirror: [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md).

### Checklist steps (01–12, Edit page only)

| # | Step | Who |
|---|------|-----|
| 01 | Link SuperOps client | On IT technician (portal / SuperOps) |
| 02 | Link Pax8 (or skip) | On IT technician (portal / SuperOps) |
| 03 | Create Portal group + save Entra IDs | On IT technician (customer Entra / GDAP) |
| 04 | Accept Portal access in customer tenant | On IT technician (customer Entra / GDAP) |
| 05 | Get SuperOps SCIM tokens | On IT technician (customer Entra / GDAP) |
| 06 | Create SuperOps SCIM app in Entra | On IT technician (customer Entra / GDAP) |
| 07 | Azure SCIM mappings + Application ID / group + start | On IT technician (customer Entra / GDAP) |
| 08 | Accept SuperOps login in customer tenant | On IT technician (customer Entra / GDAP) |
| 09 | Turn on portal sync | On IT technician (portal / SuperOps) |
| 10 | Run Dry run then Sync now | On IT technician (portal / SuperOps) |
| 11 | Test as a customer user | On IT technician (portal / SuperOps) |
| 12 | Hand off to the customer | On IT technician (portal / SuperOps) |

**Before step 01:** **Admin → Clients → Add Client** → **Create client**.

**Before step 03:** set **Customer Entra license tier** on the left.

### Auto vs manual checklist steps

| Step | Completes automatically when… | Manual tick only if… |
|------|------------------------------|----------------------|
| 01 SuperOps linked | SuperOps Account ID saved | — |
| 02 Pax8 | Pax8 off, or company ID saved | — |
| 03 Portal group + Entra IDs | Tenant ID + group ID saved | Done in Azure but IDs not pasted yet |
| 04 Portal Accept | Sync has run successfully | Accept done but sync not run yet |
| 05–07 SCIM | Legacy `superops_scim_configured` marks all three Done | Tick each substep for new clients |
| 08 SuperOps Accept | — | Accept + assign users/group |
| 09 Enable sync | Prerequisites saved + Entra sync enabled | — |
| 10 Run sync | `entra_synced_at` set after Sync now | Tick after Sync now if Last synced lagging |
| 11 Test | — | You tested in incognito |
| 12 Hand off | — | Customer notified |

**Step 09 prerequisites** (`hasEntraSyncPrerequisites()` in code):

| License tier | Required on Edit (left column) |
|--------------|--------------------------------|
| **Entra ID P1** | Entra tenant ID + Entra group ID + Entra sync enabled |
| **Entra ID Free** | Above + **SuperOps Application (client) ID** |

**Rule:** Portal sync reads the **whole tenant**, **maintains** the security group when `entra_group_id` is set, **assigns users to the SuperOps app** on Entra ID Free when `entra_superops_app_id` is set, and **writes the full SuperOps last name** to `extensionAttribute1` (e.g. `(User Mailbox)`) — **not** M365 `displayName`. Portal user names in app.onit.ltd stay plain M365 names. Entra SCIM maps `name.familyName` **Direct** from `extensionAttribute1`. Provisioning must be **ON** in Entra. See [SuperOpsEntraSync.md](SuperOpsEntraSync.md#entra-scim-attribute-mapping-one-time-per-customer).

**Dry run sync** / **Sync now** are orange buttons on the left under Microsoft Entra sync on this Edit page. Technicians work only in this portal, SuperOps, and customer M365 — never on the server.

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

**Per client (checklist 08):** an On IT technician uses delegated / GDAP access to grant admin consent in the customer tenant — see [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) § Multitenant Accept.

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

> **Canonical flow:** **Admin → Clients → Edit** checklist steps **01–12**. Do **not** use SuperOps **Client SSO**.

Every real customer (own M365 tenant) uses the same model:

1. **Platform (once):** SuperOps **Global SSO** + Entra app **SuperOps Requester SSO (On IT)** (multitenant App Registration + SAML). See §0.2 and [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).
2. **Per client:** checklist **04** Portal Graph Accept + **05–07** SCIM + **08** SuperOps SSO Accept. P1 assigns the Portal group once; Free Sync now assigns active licensed SSO users automatically.

| # | System | Action | Done |
|---|---|---|---|
| 1 | **SuperOps** | Create / confirm client; note Account ID | ☐ |
| 2 | **Portal** | **Admin → Clients** → Create → Edit checklist | ☐ |
| 3 | **Entra (customer)** | Group `On IT Portal - {Company}` + tenant ID (step 03) | ☐ |
| 4 | **On IT technician / customer tenant via GDAP** | Step 04 Portal Graph Accept | ☐ |
| 5–7 | **SuperOps + customer Entra** | Steps 05–07 SCIM tokens, app, mappings | ☐ |
| 8 | **Customer GA** | Step 08 Accept **SuperOps Requester SSO (On IT)**; P1 assign Portal group / Free no manual users | ☐ |
| 9–10 | **Portal** | Enable sync → Dry run → Sync now | ☐ |
| 11–12 | **Portal** | Test Microsoft sign-in → hand off | ☐ |

**Accept URL pattern (step 08):**

```
https://login.microsoftonline.com/{CUSTOMER-TENANT-ID}/adminconsent?client_id=bf1c303e-6015-43f7-abb2-5dfe8f67a5a1
```

Generated on Edit Client when Entra tenant ID is saved. Portal env: `SUPEROPS_REQUESTER_SSO_CLIENT_ID` (defaults to that Application ID).

**Symptom if step 08 was skipped:** Microsoft error that the account does not exist in tenant **On IT Technology Partners LTD** / cannot access `https://clientuser.superops.ai`.

---

## Part 2 — New user on an existing client

| # | System | Action | Done |
|---|---|---|---|
| 1 | **Portal / sync** | User appears after Sync now (or Admin → Users exception) | ☐ |
| 2 | **SuperOps** | Requester exists via SCIM (same email) | ☐ |
| 3 | **Entra (customer)** | P1: in Portal group; Free: automatically assigned to SuperOps Requester SSO by Sync now | ☐ |
| 4 | **Test** | Portal login → SuperOps tile → requester | ☐ |

**Not required per user:** changing SAML Reply URL, certificate, Entity ID, or SuperOps Global SSO fields.

---

## Part 3 — Do not use SuperOps Client SSO

On IT operates **Global SSO only** for all 50+ clients. SuperOps **Client SSO** (`+ Configuration` per client) is **out of scope**.

Per-client work is checklist **08**: an On IT technician uses delegated / GDAP access to **Accept** the multitenant app **SuperOps Requester SSO (On IT)** in the customer tenant. P1 assigns `On IT Portal - {Company}` once; Entra ID Free portal **Sync now** assigns all active licensed users directly. The customer does nothing. Full detail: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).

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
| Skip step 08 Accept for a new customer tenant | Microsoft: user not in On IT tenant / cannot access clientuser.superops.ai |
| Confuse step 04 with step 08 | 04 = Portal Graph; 08 = SuperOps Requester SSO |
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

**Next:** For each real customer tenant, an On IT technician completes checklist **08 Accept** through GDAP (not Client SSO). `portal.onit.ltd` remains SuperOps only.

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
| 2026-07-14 | MSP ownership made explicit: On IT technicians complete all onboarding and Accept actions via GDAP; customers do nothing |
| 2026-07-14 | Entra ID Free: Sync now auto-assigns active licensed users to SuperOps Requester SSO after step 08 Accept |
| 2026-07-14 | Live steps use multiple **Where** sections (product + menu path) so technicians know which app to open |
| 2026-07-14 | Live checklist rebuilt as 12 zero-training steps; SuperOps Accept is step 08; SCIM split into 05–07 |
| 2026-07-14 | Multitenant verified (App ID URI `onit.ltd/…` vs SAML Entity ID `clientuser.superops.ai`); Accept path; Client SSO removed from operating model |
| 2026-06-15 | Phase A SSO validated; launch path `/#/requester/login`; Plesk deployment notes |
