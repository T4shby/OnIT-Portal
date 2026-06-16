# On IT Portal — Client Onboarding (Master Checklist)

Use this when onboarding a **new client organisation** or a **new user**. It lists every system and what to configure in each.

**Related docs (read once for platform setup):**

| Doc | When |
|---|---|
| [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) | One-time Entra SAML + SuperOps Global SSO |
| [OperatorRunbook.md](OperatorRunbook.md) | Phase A test, day-to-day ops |
| [Authentication.md](Authentication.md) | Portal OAuth (app #1) |
| [Deployment.md](Deployment.md) | Production Plesk |

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

**SAML — Basic configuration (Entra)**

| Field | Value |
|---|---|
| Identifier (Entity ID) | `https://clientuser.superops.ai` |
| Reply URL (ACS) | `https://portal.onit.ltd/accounts-web/accounts/saml/response/5684471812792168448` |
| Sign on URL | *(leave empty)* |
| Logout URL | *(leave empty)* |

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
# Leave SUPEROPS_SSO_URL empty — Entra Login URL belongs in SuperOps admin only (see below)
```

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

## Future — M365 / Entra user sync

**Not built in MVP.** Users are created manually in the portal (and SuperOps). Deleting someone in M365 does **not** remove them from the portal.

| Approach | Phase | Notes |
|---|---|---|
| Manual Admin → Users | MVP (now) | [Part 2](ClientOnboarding.md#part-2--new-user-on-an-existing-client) |
| Entra **group** drives access to SuperOps SAML | Now | Add/remove group member — portal still manual |
| **SCIM** provisioning (Entra → portal) | Phase 2+ | Auto create/update/deactivate `users` from group membership |
| **Microsoft Graph** delta sync job | Phase 2+ | Nightly sync members of per-client Entra groups |
| SuperOps API auto-create requester | Phase 3+ | On first portal login when API configured |

**Recommended future design:** one Entra security group per client (`Client - {Name} - Portal`). A scheduled job or SCIM endpoint syncs group members → `users` table (`is_active=false` when removed from group). SuperOps requester rows remain manual or API-driven until Phase 3.

See [Roadmap.md](Roadmap.md) Phase 2.

---

## Part 1 — New client organisation

Choose **Path A** or **Path B** before you start.

| Path | Client's Microsoft tenant | SuperOps SSO |
|---|---|---|
| **A — Same On IT tenant** | Users are in **On IT** M365 (unusual for external clients) | **Global SSO** (already configured) |
| **B — Client's own M365** | Users in **client's** tenant (normal MSP model) | **Client SSO** per client (see Part 3) |

Most real customers are **Path B**. Path A + Global SSO suits internal/test users homed in On IT's directory.

---

### Path A — New client (users in On IT tenant)

Use when every requester has an `@onit.ltd` (or guest) account in **On IT's** Entra.

#### Checklist — new client (Path A)

| # | System | Action | Done |
|---|---|---|---|
| 1 | **SuperOps** | **Clients** → create client (or confirm exists) | ☐ |
| 2 | **SuperOps** | Note **Account ID** for ticket mapping | ☐ |
| 3 | **Portal** | **Admin → Clients** → Create: name, slug, active | ☐ |
| 4 | **Portal** | Edit client: **SuperOps Account ID**, **SuperOps SSO enabled** ✓ | ☐ |
| 5 | **Entra** | Create security group: `Client - {Name} - Portal` | ☐ |
| 6 | **Entra** | Assign group to **SuperOps Requester SSO (On IT)** app (once per group) | ☐ |
| 7 | **Portal** | **Admin → Users** → create each user (email, role, client) | ☐ |
| 8 | **SuperOps** | Client → **Users** → add each **requester** (same emails) | ☐ |
| 9 | **Entra** | Add each user to client's security group | ☐ |
| 10 | **Test** | One user: portal login → SuperOps → requester portal | ☐ |

#### Portal — Admin → Clients (fields)

| Field | What to enter |
|---|---|
| Name | Client display name |
| Slug | URL-safe identifier |
| SuperOps Account ID | From SuperOps client record |
| SuperOps SSO enabled | ✓ if using SSO launch |
| Active | ✓ |

#### Portal — Admin → Users (per person)

| Field | What to enter |
|---|---|
| Email | Must match Microsoft sign-in address |
| Name | Display name |
| Role | `client_user` or `client_admin` |
| Client | This client |
| Active | ✓ |

#### Entra — group pattern (scalable)

```
Group name:     Client - Acme Corp - Portal
Members:        all Acme portal users (@onit.ltd or guests)
App assignment: SuperOps Requester SSO (On IT)  ← assign GROUP once
```

New hire at Acme → add to group only (steps 7–9 for portal + SuperOps user rows).

---

### Path B — New client (client's own M365 tenant) — most common

Portal login already works (multi-tenant OAuth): provision `users.email` and they sign in with **their** work account.

**Global SSO does not apply** — do **not** add client staff to On IT's `SuperOps Requester SSO` Entra app.

| # | System | Action | Done |
|---|---|---|---|
| 1 | **SuperOps** | Create / confirm client | ☐ |
| 2 | **Portal** | **Admin → Clients** → create + SuperOps Account ID | ☐ |
| 3 | **SuperOps** | **Client SSO** setup (Part 3) — client's Entra SAML app | ☐ |
| 4 | **Portal** | Create users with **client work emails** | ☐ |
| 5 | **SuperOps** | Add **requesters** with same emails | ☐ |
| 6 | **Portal** | Share portal URL; client signs in with Microsoft | ☐ |
| 7 | **SuperOps access** | Client uses Client SSO URL or requester login on `portal.onit.ltd` | ☐ |

**Note:** Per-client `SUPEROPS_SSO_URL` in the database is **not** implemented yet. Until then, portal **SuperOps** button uses the **global** `SUPEROPS_SSO_URL` (On IT tenant). For Path B clients, they may need to open `portal.onit.ltd` directly and use **Client SSO** login until per-client launch URLs are built (Roadmap / Phase 3).

---

## Part 2 — New user on an existing client

| # | System | Action | Done |
|---|---|---|---|
| 1 | **Portal** | **Admin → Users** → Create (email, role, client) | ☐ |
| 2 | **SuperOps** | Client → **Users** → Add requester (same email) | ☐ |
| 3 | **Entra** (Path A only) | Add user to `Client - {Name} - Portal` group | ☐ |
| 4 | **Test** | User signs in at portal URL | ☐ |

**Not required per user:** changes to SAML Reply URL, certificate, or `SUPEROPS_SSO_URL` (unless Client SSO path).

---

## Part 3 — Client SSO (client's own Entra tenant)

When a client has **their own** Microsoft 365 and wants passwordless SuperOps login.

**One-time per client** in SuperOps + client's Entra (not On IT's Global SSO app).

| # | Where | Action |
|---|---|---|
| 1 | **SuperOps** | **Requester Login → SSO Protected → Client SSO** → **+ Configuration** |
| 2 | **SuperOps** | Copy **client-specific** Entity ID + Consumer Service URL |
| 3 | **Client's Entra** | New enterprise app (non-gallery), SAML — use SuperOps values from step 2 |
| 4 | **Client's Entra** | Claims: `email`, `firstname`, `lastname` (lowercase) |
| 5 | **Client's Entra** | Cert → SuperOps; Login URL → SuperOps |
| 6 | **Client's Entra** | Client IT assigns their users/groups to **their** SAML app |
| 7 | **SuperOps** | Link configuration to this client |
| 8 | **Portal** | Portal users still created in Admin (multi-tenant OAuth unchanged) |

Official guide: [Setting up Requester SSO in SuperOps](https://support.superops.com/en/articles/11583025-setting-up-requester-sso-in-superops) (Client SSO section).

Record per client in your internal wiki:

| Client | SuperOps account | Client SSO name | Client tenant ID | Notes |
|---|---|---|---|---|
| Example Ltd | … | SuperOps Requester - Example | … | Configured 2026-… |

---

## Part 4 — Verification tests

### After platform setup (once)

| Test | Steps | Pass |
|---|---|---|
| SAML direct | Private window → Entra Login URL → test requester | ☐ |
| Portal → SuperOps | Test user → dashboard → **SuperOps** → Microsoft → requester dashboard | ☐ |
| Portal OAuth | Test user → `/login` → Microsoft | ☐ |
| Pax8 | Dashboard → **Pax8** → opens Pax8 | ☐ |

### After each new client / user

| Test | Pass |
|---|---|
| Portal login with work email | ☐ |
| Dashboard shows SuperOps + Pax8 | ☐ |
| SuperOps opens as **requester** (not technician) | Use `portal.test@onit.ltd`; not `tom.ashby@onit.ltd` | ☐ |
| User sees only their client's data | ☐ |

---

## Part 5 — What NOT to do

| Don't | Why |
|---|---|
| Test requester SSO as `tom.ashby@onit.ltd` | SuperOps maps Tom to **technician** — misleading results |
| Assign technicians to Requester SSO app | Technician ≠ requester |
| Reuse portal OAuth app for SuperOps SAML | Wrong protocol and URLs |
| Add client staff (own M365) to On IT Global SSO app | They live in a different tenant |
| Use seeded `@*.example` emails for real tests | Not real Entra accounts |
| Change Reply URL without updating Entra | SAML breaks for everyone on Global SSO |

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

**Next:** Onboard first real client users (Path B in most cases). `portal.onit.ltd` remains SuperOps only.

See [OperatorRunbook.md](OperatorRunbook.md) Phase A for click-by-click.

---

## Quick copy — new client worksheet

```
Client name:     _______________________
Portal slug:     _______________________
Path:            [ ] A (On IT tenant)  [ ] B (client M365)

SuperOps account ID:  _______________________
SuperOps SSO enabled: [ ] Yes  [ ] No (Path B until Client SSO)

Entra group (Path A): Client - __________ - Portal

Users:
  Email                    Portal role    SuperOps requester    Entra group
  ____________________     __________     [ ]                 [ ]
  ____________________     __________     [ ]                 [ ]

Tested by: __________  Date: __________
```

---

## Change log

| Date | Change |
|---|---|
| 2026-06-15 | Phase A SSO validated; launch path `/#/requester/login`; Plesk deployment notes |
