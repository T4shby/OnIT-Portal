# On IT Portal — Operator Runbook

Action checklist for Tom / On IT admins. Work top to bottom. Tick items as you go.

**Detail docs:** [ClientOnboarding.md](ClientOnboarding.md) · [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) · [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md) · [LocalDevelopment.md](LocalDevelopment.md) · [Deployment.md](Deployment.md)

> **New client?** Use **Admin → Clients → Edit** (in-app setup wizard), or [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md). Background: [ClientOnboarding.md](ClientOnboarding.md).

---

## Phase A0 — SuperOps Technician SSO (On IT staff)

**Full guide:** [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md) — copy requester setup, different values.

**Why two Entra apps?** Requester Reply URL = `portal.onit.ltd/...` · Technician Reply URL = `usauth.superops.ai/...` · Different Entity IDs. See comparison table in guide.

### T1. SuperOps — Technician Login SSO

1. **Settings → Technician Login → SSO** tab (not Requester Login)
2. **Global SSO Configurations** → ON
3. Copy **Consumer Service URL** from Step 1:

```
https://usauth.superops.ai/api/federated_auth/saml/response/5684471812792168448
```

4. Leave Step 2 empty until T2 complete — then paste IDP Login URL + certificate → **Save**

### T2. Entra — create Technician SAML app

**You are here if creating the app:**

1. Entra → **Enterprise applications** → **+ New application** → **Create your own application**
2. Name: `SuperOps Technician SSO (On IT)`
3. Select: **Integrate any other application you don't find in the gallery (Non-gallery)**
4. Click **Create**
5. **Single sign-on** → **SAML** → **Edit** Basic configuration:
   - Identifier: `https://superops.ai`
   - Reply URL: URL from T1 (usauth URL above)
6. **Attributes & Claims** → only `email`, `firstname`, `lastname` (empty namespace)
7. **Users and groups** → assign **`SuperOps Technicians`** group (On IT staff only)
8. Copy **Login URL** + download **Certificate (Base64)**

### T3. SuperOps — finish Step 2

1. **Technician Login → SSO** → paste **IDP Login URL** + **Certificate** (no BEGIN/END lines)
2. **Save** → reload page → confirm fields not blank

### T4. Portal `.env` (production)

```env
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_TECHNICIAN_LOGIN_PATH=/#/technician/login
SUPEROPS_SSO_ENABLED=true
```

`php artisan config:clear && php artisan optimize`

### T5. Test

- [ ] `https://portal.onit.ltd/#/technician/login` → **Microsoft** (not Login with Email)
- [ ] Portal → `tom.ashby@onit.ltd` → **SuperOps** tile → technician console

**Workaround until T3 done:** `SUPEROPS_TECHNICIAN_LOGIN_PATH=/#/requester/login` (uses existing requester SAML)

---

## Phase A — SuperOps requester Client SSO rollout

Global requester SSO is retired. Start with 3R as the pilot:

1. SuperOps → Requester Login → SSO Protected → disable **Global SSO**.
2. **Client SSO → + Configuration** → select 3R → generate Entity ID + Consumer Service URL.
3. Through GDAP create `SuperOps Requester SSO - 3R Systems Limited` in the 3R Entra tenant.
4. Configure customer-specific SAML, exact claims, Azure Login URL and certificate.
5. Assign `On IT Portal - 3R Systems Limited` (P1).
6. Test in an InPrivate window with a real 3R requester.

Full click path: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) and live checklist step 08.

---

## Phase A2 — Pax8 SSO launch (production)

Detail: [Pax8Integration.md](Pax8Integration.md) · **Full setup:** [Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md)

### P1. Pax8 Enterprise SSO (On IT technicians) — Primary Partner Admin

1. **Before SSO:** Pax8 → **Users** — each technician is an **app user**; UPN must match Microsoft (e.g. `tom.ashby@onit.ltd`)
2. **Admin → My Partner Profile → Enterprise SSO → Azure AD**
3. **Email Domain:** `onit.ltd` (+ domain aliases if any)
4. **Create** → add DNS **TXT** at registrar → **Verify Domain** → **Finalize**
5. First federated login: **Global Admin** accepts Microsoft consent for Pax8
6. Optional: Entra → **Enterprise applications → Pax8** → assign `Pax8 Technicians` group

> **Partner Admin** can use SSO after setup; only **Primary Partner Admin** can configure it.

### P2. Portal `.env` (production)

```env
PAX8_SSO_ENABLED=true
PAX8_PARTNER_PORTAL_URL=https://app.pax8.com
PAX8_PARTNER_LOGIN_PATH=/login
PAX8_COMPANY_URL_TEMPLATE=https://app.pax8.com/companies/{companyId}
PAX8_LOGIN_HINT_ENABLED=true
```

> **`login_hint` pre-fills email only.** Technician Microsoft SSO requires Pax8 Enterprise SSO (P1). After setup: identifier page → **Continue** → Microsoft → Pax8. See [Pax8Integration.md — Troubleshooting](Pax8Integration.md#troubleshooting).

### P3. Deploy on server

After Plesk Git pull, run the full block in [Deployment.md — Updating the Application](Deployment.md#updating-the-application) (includes `migrate`, `PortalLinkSeeder`, `optimize`).

### P4. Per client

1. **Admin → Clients → Edit** → **Pax8 company ID** + **Pax8 access enabled** (from Pax8 → Companies)
2. Pax8 → company → **Users** — matching emails ([Pax8CustomerAccess.md](Pax8CustomerAccess.md))
3. Client users need a Pax8 company user (Pax8 customer Microsoft SSO not available yet)

### P5. Test

- [ ] Technician → dashboard → **Pax8** → `app.pax8.com/login?login_hint=…` → Auth0 identifier (email pre-filled)
- [ ] After Enterprise SSO (P1): click **Continue** → Microsoft → Pax8 partner dashboard
- [ ] Client with `pax8_company_id` → **Pax8** → company view
- [ ] Client without company ID → Pax8 tile hidden on dashboard

---

## Phase B — Day-to-day: new client employee (MVP)

Use this every time someone new needs the portal.

### B1. On IT Portal

1. **Admin** → **Users** → **Create**
2. Email = their **work Microsoft address** (must match what they will sign in with)
3. Role: `client_requester`, `client_billing_admin`, or `client_admin`
4. Client: select their organisation
5. **Save**

### B2. SuperOps

1. **Clients** → their organisation → **Users** → **Add requester**
2. Same email as B1
3. For many users: use SuperOps bulk import if available

### B3. Entra Client SSO access

- P1: requester must be in `On IT Portal - {Company}`, assigned to that customer's Client SSO app.
- Free: portal Sync now assigns active licensed users directly to that customer's Client SSO app.

### B4. Client tries the portal

1. They go to portal URL → **Sign in with Microsoft**
2. Dashboard → **SuperOps** or **Pax8**

**With Entra sync enabled:** add the user to the M365 security group — portal and SuperOps update automatically (see [AccessAndSync.md](AccessAndSync.md)). Without sync, portal users are added manually in Admin → Users.

---

## Phase C — Real clients on their own M365 (canonical)

For a 50–500 person client on **their** Microsoft tenant:

1. **SuperOps** → **Requester Login** → **Client SSO** → **+ Configuration** for that client
2. On IT technician uses GDAP to create the customer's Entra SAML app with the client-specific Entity ID / Consumer URL from SuperOps
3. Portal users are synced from the customer tenant; portal OAuth is already multi-tenant
4. Do **not** add client staff to On IT’s `SuperOps Requester SSO` Entra app

Document each client’s SSO in your internal wiki when Client SSO is live.

---

## Phase D — Optional enhancements

### D1. SuperOps API (embedded support + email sync on login)

1. SuperOps → **Settings → Your Profile → API Token** → generate
2. `.env`:

```env
SUPEROPS_API_TOKEN=...
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REGION=us
```

3. `php artisan config:clear`
4. Links existing SuperOps requester by email on login — does **not** create new requesters

### D2. Production (Plesk)

Follow [Deployment.md](Deployment.md):

- [ ] Deploy code to Plesk
- [ ] Production `.env` (MySQL, `APP_URL`, Entra redirect URI, SuperOps vars)
- [ ] `php artisan migrate --force`
- [ ] Register production redirect URI in **On IT Portal** Entra app (OAuth)
- [ ] SSL via Let's Encrypt

### D3. Entra groups at scale

1. Create `On IT Portal - {Company}` in each customer tenant
2. P1: assign that group to the customer's SCIM and Client SSO apps
3. Free: portal Sync directly assigns users using each saved Application ID

---

## Quick reference — On IT values

| Item | Value |
|---|---|
| Portal (local) | http://localhost:8000/login |
| Portal (production) | https://app.onit.ltd/login |
| Portal Entra OAuth redirect | `https://app.onit.ltd/auth/microsoft/callback` |
| Portal Entra app | OAuth — `MICROSOFT_CLIENT_ID` in `.env` |
| SuperOps requester portal | https://portal.onit.ltd |
| SuperOps SAML app name | `SuperOps Requester SSO - {Company}` in customer tenant |
| SAML values | Client-specific Entity ID / Consumer URL from SuperOps; Login URL / certificate from customer Azure |
| Pax8 launch route | `/integrations/pax8/launch` (dashboard tile, same tab) |
| Pax8 partner URL | `https://app.pax8.com/login` (`PAX8_PARTNER_PORTAL_URL` + `PAX8_PARTNER_LOGIN_PATH`) |
| Pax8 company URL | `https://app.pax8.com/companies/{companyId}` — set per client in Admin |

---

## What you can skip

| Item | Reason |
|---|---|
| Technician SSO Entra app | **Required** for one-click technician SSO — see [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md). Portal launches `portal.onit.ltd/#/technician/login`; SuperOps Technician Login SSO completes SAML. |
| Assigning Tom to Requester SSO | You are MSP technician |
| Per-user Entra SAML assignment | Use groups instead |
| `SUPEROPS_API_TOKEN` | Only needed for `/support` embed, not SSO launch |

---

## Phase E — Production (Plesk)

Deploy the **Laravel app** to **`app.onit.ltd`**. **`portal.onit.ltd` stays SuperOps** — do not host Laravel there.

Full steps: [Deployment.md](Deployment.md)

- [ ] Subdomain + SSL + MySQL in Plesk
- [ ] Git deploy or SFTP upload
- [ ] Production `.env` (`APP_URL`, MySQL, Entra redirect URI)
- [ ] `composer install --no-dev`, `npm run build`, `php artisan migrate --force`
- [ ] Entra → add production redirect URI for Laravel subdomain
- [ ] Smoke test: login → dashboard → SuperOps SSO

---

## Order of work (summary)

```
NOW     → Production live at app.onit.ltd; finish post-deploy hardening/tasks
NEXT    → Phase B onboarding flow for real client users
ONGOING → Phase B (each new portal user)
LATER   → Phase C (Client SSO per real client tenant)
OPTION  → Phase D (API, groups)
```
