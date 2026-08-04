# On IT Portal — Client Onboarding (Master Checklist)

Use this when onboarding a **new client organisation** or a **new user**. It lists every system and what to configure in each.

> **Requester SSO — 2026-07-14:** checklist **08** now configures SuperOps
> **Client SSO** with one client-specific SAML enterprise application in each
> customer's own Entra tenant. Do not use the retired Global SSO admin-consent
> design. Portal OAuth, SCIM and technician SSO remain unchanged. See
> [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).

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
| [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) | Per-customer requester Client SSO |
| [Authentication.md](Authentication.md) | Portal OAuth |
| [Deployment.md](Deployment.md) | Plesk (Tom only) |

---

## Who performs each step (MSP — ~100 clients)

Every step is performed by **On IT MSP technicians on the customer’s behalf**. The label shows **where** you work, not a different company. The customer does not receive setup links, sign in, or complete checklist tasks.

| Label in app | Where | Examples |
|--------------|-------|----------|
| **On IT technician (portal / SuperOps)** | app.onit.ltd admin, SuperOps MSP console, Pax8 partner | Client record, Account ID, sync buttons |
| **On IT technician (customer Entra / GDAP)** | **Private browser** → portal.azure.com signed **directly** into the **customer** tenant via GDAP (never On IT first then switch). Entra left **Manage** → Groups / Enterprise applications | Security group, SCIM app, Portal Graph Accept (04), SuperOps Client SSO app (08) |

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
| **Start here** / orange Accept button | Generated action first for Portal Graph Accept (step 04 only) |
| **Do this** | Numbered clicks only |
| **Done when** | How you know the step finished |
| **Remember** | One short warning max |

Full click-by-click text lives in the app. Mirror: [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md).

### Checklist steps (01–12, Edit page only)

| # | Step | Who |
|---|------|-----|
| 01 | Link SuperOps client | On IT technician (portal / SuperOps) |
| 02 | Link Pax8 (or skip) | On IT technician (portal / SuperOps) |
| 03 | Connect Microsoft tenant (tenant / licence / group auto) | On IT technician (customer Entra / GDAP) |
| 04 | Accept Portal Graph (starts bootstrap) | On IT technician (customer Entra / GDAP) |
| 05 | Get SuperOps SCIM tokens | On IT technician (customer Entra / GDAP) |
| 06 | SuperOps SCIM app (auto-created on Connect; confirm ID) | On IT technician (customer Entra / GDAP) |
| 07 | SCIM tokens into Provisioning + mappings + start | On IT technician (customer Entra / GDAP) |
| 08 | Configure SuperOps Client SSO (Entra app shell auto; SAML + SuperOps manual) | On IT technician (SuperOps + customer Entra / GDAP) |
| 09 | Turn on portal sync | On IT technician (portal / SuperOps) |
| 10 | Run Dry run then Sync now | On IT technician (portal / SuperOps) |
| 11 | Test as a customer user | On IT technician (portal / SuperOps) |
| 12 | Hand off to the customer | On IT technician (portal / SuperOps) |

Deployment resets existing step 08 completions because they represented the retired Global SSO Accept, not a working customer Client SSO configuration.

**Before step 01:** **Admin → Clients → Add Client** → **Create client**.

**Steps 03–04 (preferred):** orange **Connect Microsoft tenant** — private browser / GDAP → Accept once. Portal bootstraps: `entra_tenant_id`, Free/P1 from `subscribedSkus`, creates/finds portal group, creates SuperOps SCIM + Client SSO Entra apps (App role User), saves Application (client) IDs. Requires platform Graph **Group.ReadWrite.All** + **Application.ReadWrite.All** (see [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) Step 0).

### Auto vs manual checklist steps

| Step | Completes automatically when… | Manual tick only if… |
|------|------------------------------|----------------------|
| 01 SuperOps linked | SuperOps Account ID saved | — |
| 02 Pax8 | Pax8 off, or company ID saved | — |
| 03 Tenant + group | Tenant ID + group ID saved (Connect bootstrap) | Legacy paste if Graph create failed |
| 04 Portal Accept | Consent + bootstrap, or Sync has run successfully | — |
| 05 SCIM tokens | — | SuperOps Generate Tokens |
| 06 SCIM app | `entra_superops_app_id` filled | Tick if app exists but ID not saved |
| 07 SCIM provisioning | — | Tokens + mappings + Start provisioning |
| 08 Client SSO | — | SuperOps Client SSO config + SAML values (Entra shell often from Connect) |
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
   requester users     portal users         customer Client SSO app
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

### 0.2 SuperOps requester Client SSO

There is no shared requester SAML application in the On IT tenant. Each customer gets:

- SuperOps Client SSO configuration: `{Company} Entra SSO`
- Customer Entra non-gallery app: `SuperOps Requester SSO - {Company}`
- Client-specific Entity ID and Consumer Service URL generated by SuperOps
- Customer Azure Login URL and certificate pasted back into SuperOps
- P1: assign `On IT Portal - {Company}`; Free: portal Sync assigns users using the saved Client SSO Application ID

**SAML claims — exact lowercase names:**

| Claim name | Source attribute |
|---|---|
| `email` | `user.mail` |
| `firstname` | `user.givenname` |
| `lastname` | `user.surname` |

**Portal `.env`**

```env
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_SSO_ENABLED=true
```

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

> **Canonical flow:** **Admin → Clients → Edit** checklist steps **01–12**. Checklist 08 configures SuperOps **Client SSO**.

Every real customer (own M365 tenant) uses the same model:

1. **Platform (once):** requester portal launch + SuperOps Client SSO feature enabled; Global SSO disabled.
2. **Per client:** checklist **04** Portal Graph Accept + **05–07** SCIM + **08** customer-specific SuperOps Client SSO. P1 assigns the Portal group once; Free Sync assigns active licensed SSO users automatically.

| # | System | Action | Done |
|---|---|---|---|
| 1 | **SuperOps** | Create / confirm client; note Account ID | ☐ |
| 2 | **Portal** | **Admin → Clients** → Create → Edit checklist | ☐ |
| 3 | **Entra (customer)** | Group `On IT Portal - {Company}` + tenant ID (step 03) | ☐ |
| 4 | **On IT technician / customer tenant via GDAP** | Step 04 Portal Graph Accept | ☐ |
| 5–7 | **SuperOps + customer Entra** | Steps 05–07 SCIM tokens, app, mappings | ☐ |
| 8 | **On IT technician + customer tenant via GDAP** | Generate SuperOps Client SSO values; create customer Entra SAML app; P1 assign group / Free save SSO Application ID | ☐ |
| 9–10 | **Portal** | Enable sync → Dry run → Sync now | ☐ |
| 11–12 | **Portal** | Test Microsoft sign-in → hand off | ☐ |

Step 08 has no admin-consent URL. SuperOps generates the customer-specific Entity ID and Consumer Service URL; the technician creates that customer's SAML app through GDAP.

---

## Part 2 — New user on an existing client

| # | System | Action | Done |
|---|---|---|---|
| 1 | **Portal / sync** | User appears after Sync now (or Admin → Users exception) | ☐ |
| 2 | **SuperOps** | Requester exists via SCIM (same email) | ☐ |
| 3 | **Entra (customer)** | P1: in Portal group; Free: automatically assigned to SuperOps Requester SSO by Sync now | ☐ |
| 4 | **Test** | Portal login → SuperOps tile → requester | ☐ |

**Not required per user:** changing SAML Reply URL, certificate, Entity ID, or SuperOps Client SSO fields.

---

## Part 3 — SuperOps Client SSO

On IT uses **Client SSO** for all customer requesters. Each customer's Entra app stays in that customer's tenant, so no customer B2B guests are created in On IT.

Per-client work is checklist **08**. P1 assigns `On IT Portal - {Company}` once; Entra ID Free saves the separate Client SSO Application ID so portal **Sync now** can assign active licensed users directly. Full detail: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).

---

## Part 4 — Verification tests

### After Client SSO transition (once)

| Test | Steps | Pass |
|---|---|---|
| SuperOps requester mode | Global SSO disabled; Client SSO available | ☐ |
| Portal OAuth | Test user → `/login` → Microsoft | ☐ |
| Pax8 | Dashboard → **Pax8** → launch (if used) | ☐ |

### After each new client

| Test | Pass |
|---|---|
| Step 08 Client SSO completed for that tenant | ☐ |
| Portal login with customer work email | ☐ |
| Dashboard shows SuperOps (+ Pax8 if enabled) | ☐ |
| SuperOps opens as **requester** (not technician) | ☐ |
| No “account does not exist in On IT tenant” at Microsoft | ☐ |
---

## Part 5 — What NOT to do

| Don't | Why |
|---|---|
| Sign into On IT Azure first, then switch to the customer | Wrong habit — technicians open a **private browser** and land on the **customer** tenant via GDAP |
| Skip Entra left **Manage** and hunt for Groups | Groups / Enterprise applications sit under **Manage** on the Entra Overview sidebar |
| Test requester SSO as `tom.ashby@onit.ltd` | SuperOps maps Tom to **technician** — misleading results |
| Assign technicians to Requester SSO app | Technician ≠ requester |
| Reuse portal OAuth app for SuperOps SAML | Wrong protocol and URLs |
| Reuse another customer's Client SSO Entity ID / Reply URL | Each configuration is customer-specific |
| Reuse the retired shared Global SSO app | Sends customers to the wrong tenant and conflicts with Azure Multitenant identifiers |
| Confuse step 04 with step 08 | 04 = Portal Graph; 08 = SuperOps Requester SSO |
| Change Reply URL / cert without updating both ends | SAML breaks for that customer's Client SSO |

---

## Part 6 — Client SSO rollout status

The former On IT Global SSO test is historical and must not be used as proof that customer-tenant login works. Validate the new model with a real customer Client SSO configuration, starting with 3R.

**Launch path:** `SUPEROPS_REQUESTER_LOGIN_PATH=/#/requester/login` — **not** `/#/login/requester` (invalid; shows role chooser).

**Do not test requester SSO as `tom.ashby@onit.ltd`** — MSP technician in SuperOps. Portal blocks `super_admin` from SuperOps launch.

**Next:** For each real customer tenant, an On IT technician completes checklist **08 Client SSO** through GDAP. `portal.onit.ltd` remains the requester portal.

See [OperatorRunbook.md](OperatorRunbook.md) Phase A for click-by-click.

---

## Quick copy — new client worksheet

```
Client name:     _______________________
Portal slug:     _______________________
Entra tenant ID: _______________________

SuperOps account ID:  _______________________
Step 04 Portal Graph Accept: [ ] Done
Step 08 SuperOps Client SSO: [ ] Done
Client SSO Application (client) ID: _______________________
Group On IT Portal - _______________ assigned in customer tenant: [ ]

Tested by: __________  Date: __________
```

---

## Change log

| Date | Change |
|---|---|
| 2026-07-14 | Replaced requester Global SSO multitenant Accept with customer-owned SuperOps Client SSO; no On IT guest accounts |
| 2026-07-14 | Retired AADSTS1003031 requester Accept troubleshooting with the Global SSO design |
| 2026-07-14 | Step 03: read customer Entra Overview → License before setting portal tier |
| 2026-07-14 | MSP ownership made explicit: On IT technicians complete all onboarding and Accept actions via GDAP; customers do nothing |
| 2026-07-14 | Entra ID Free: Sync now auto-assigns active licensed users to the per-customer Client SSO app after step 08 |
| 2026-07-14 | Live steps use multiple **Where** sections (product + menu path) so technicians know which app to open |
| 2026-07-14 | Live checklist rebuilt as 12 zero-training steps; Client SSO is step 08; SCIM split into 05–07 |
| 2026-07-14 | Retired failed Global SSO Multitenant experiment; Client SSO restored as operating model |
| 2026-06-15 | Phase A SSO validated; launch path `/#/requester/login`; Plesk deployment notes |
