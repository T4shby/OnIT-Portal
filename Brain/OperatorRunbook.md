# On IT Portal — Operator Runbook

Action checklist for Tom / On IT admins. Work top to bottom. Tick items as you go.

**Detail docs:** [ClientOnboarding.md](ClientOnboarding.md) · [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) · [LocalDevelopment.md](LocalDevelopment.md) · [Deployment.md](Deployment.md)

> **New client?** Start with [ClientOnboarding.md](ClientOnboarding.md) — not this file alone.

---

## Phase A — Finish SuperOps Requester SSO (do now)

### A1. Entra — fix claims (if not done)

1. [Azure Portal](https://portal.azure.com) → **Entra ID** → **Enterprise applications** → **SuperOps Requester SSO (On IT)**
2. **Single sign-on** → **Attributes & Claims** → **Edit**
3. Ensure **exactly** these three additional claims exist (**lowercase** names):

| Claim name | Source attribute |
|---|---|
| `email` | `user.userprincipalname` |
| `firstname` | `user.givenname` |
| `lastname` | `user.surname` |

If SuperOps shows **Error 1027** after Microsoft login, change the `email` claim source to `user.userprincipalname` (common for test mailboxes without Exchange `mail`).

4. Remove uppercase variants (`EMAIL`, `FIRSTNAME`, `LASTNAME`) or old defaults (`emailaddress`, `givenname`, `surname`)
5. **Save**

### A2. Entra — create test group (do not assign yourself)

1. **Entra ID** → **Groups** → **New group**
2. Name: `SuperOps Test Requesters`
3. Type: **Security**
4. Add **one test user** (not your technician account) — e.g. a seeded client user or a dedicated test mailbox
5. **Enterprise applications** → **SuperOps Requester SSO (On IT)** → **Users and groups** → **+ Add user/group**
6. **Groups** tab → select `SuperOps Test Requesters` → **Assign**

> Do **not** assign `Tom.Ashby@onit.ltd` — you are a technician, not a requester.

### A3. SuperOps — test requester user

1. Sign in to SuperOps MSP console
2. **Clients** → pick a test client (e.g. Acme) → **Users**
3. **Add requester** with the **same email** as the test Entra user from A2
4. Confirm **Requester Login** → **SSO Protected** → **Global SSO** is **ON** and saved:
   - Consumer URL: `https://portal.onit.ltd/accounts-web/accounts/saml/response/5684471812792168448`
   - IDP Login URL: `https://login.microsoftonline.com/586cc505-d298-4131-a3dc-9d1cd7c5ac0b/saml2`
   - Certificate: pasted (no BEGIN/END lines)
   - Logout URL: leave empty

### A4. On IT Portal — test user

1. **Admin** → **Users** → create user with **same email** as A2/A3
2. Set role `client_user` or `client_admin`, assign to the test client
3. Confirm `.env` on dev machine:

```env
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_SSO_ENABLED=true
```

Entra Login URL stays in **SuperOps admin only** — not portal `.env`.

4. Run: `php artisan config:clear`

### A5. Test SSO end-to-end

**Test 1 — SuperOps portal (private/incognito browser)**

- [ ] Open: `https://portal.onit.ltd/#/requester/login` — requester login (not role chooser)
- [ ] Redirects to **Microsoft** (not “Login with Email”)
- [ ] Confirm **`portal.test@onit.ltd`** at Microsoft
- [ ] Land in SuperOps **requester** dashboard (`/#/client-home`)

**Test 2 — Via portal**

- [ ] Private/incognito → http://localhost:8000/login → sign in as **`portal.test@onit.ltd`**
- [ ] Dashboard → **SuperOps** — direct redirect, no interim page
- [ ] Microsoft → requester dashboard (no role chooser)

**Do not use `tom.ashby@onit.ltd` for these tests** — SuperOps maps Tom to technician even on the requester path.

**If it fails:** note the exact error → check [SuperOpsRequesterSsoSetup.md — Troubleshooting](SuperOpsRequesterSsoSetup.md#troubleshooting)

---

## Phase B — Day-to-day: new client employee (MVP)

Use this every time someone new needs the portal.

### B1. On IT Portal

1. **Admin** → **Users** → **Create**
2. Email = their **work Microsoft address** (must match what they will sign in with)
3. Role: `client_user` or `client_admin`
4. Client: select their organisation
5. **Save**

### B2. SuperOps

1. **Clients** → their organisation → **Users** → **Add requester**
2. Same email as B1
3. For many users: use SuperOps bulk import if available

### B3. Entra (Global SSO — On IT tenant users only)

- If the person is in **On IT’s** Entra tenant: add them to `SuperOps Test Requesters` (or a per-client group you create later)
- If the person is in **the client’s own** M365 tenant: **skip** — Global SSO does not apply; see Phase C

### B4. Client tries the portal

1. They go to portal URL → **Sign in with Microsoft**
2. Dashboard → **SuperOps** or **Pax8**

**With Entra sync enabled:** add the user to the M365 security group — portal and SuperOps update automatically (see [AccessAndSync.md](AccessAndSync.md)). Without sync, portal users are added manually in Admin → Users.

---

## Phase C — Real clients on their own M365 (plan next)

Global SSO (Phase A) only covers users in **On IT’s** Entra tenant.

For a 50–500 person client on **their** Microsoft tenant:

1. **SuperOps** → **Requester Login** → **Client SSO** → **+ Configuration** for that client
2. Work with the client’s IT admin to create **their** Entra SAML app (same pattern as Phase A, but their tenant + client-specific Entity ID / Consumer URL from SuperOps)
3. Portal users still created in **Admin → Users** (Phase B1) — portal OAuth is already multi-tenant
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

### D3. Entra groups at scale (On IT tenant requesters only)

1. Create one security group per client, e.g. `Client - Acme - Portal`
2. Assign each group to **SuperOps Requester SSO (On IT)** once
3. New hire → add to group in Entra (or dynamic membership rule)

---

## Quick reference — On IT values

| Item | Value |
|---|---|
| Portal (local) | http://localhost:8000/login |
| Portal (production) | https://app.onit.ltd/login |
| Portal Entra OAuth redirect | `https://app.onit.ltd/auth/microsoft/callback` |
| Portal Entra app | OAuth — `MICROSOFT_CLIENT_ID` in `.env` |
| SuperOps requester portal | https://portal.onit.ltd |
| SuperOps SAML app name | SuperOps Requester SSO (On IT) |
| SAML app client ID | `bf1c303e-6015-43f7-abb2-5dfe8f67a5a1` |
| On IT tenant ID | `586cc505-d298-4131-a3dc-9d1cd7c5ac0b` |
| SAML Login URL | `https://login.microsoftonline.com/586cc505-d298-4131-a3dc-9d1cd7c5ac0b/saml2` |
| SAML Reply URL | `https://portal.onit.ltd/accounts-web/accounts/saml/response/5684471812792168448` |
| Pax8 | https://app.pax8.com (external link on dashboard) |

---

## What you can skip

| Item | Reason |
|---|---|
| Technician SSO Entra app | Not needed for client portal |
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
