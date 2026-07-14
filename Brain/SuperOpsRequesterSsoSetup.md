# SuperOps Requester SSO — Setup Guide (On IT)

Step-by-step guide to enable **Microsoft Entra ID SSO for SuperOps requesters only**. Technicians are **out of scope** — On IT staff continue to sign in to the MSP console separately.

**Official SuperOps reference:** [Setting up Requester SSO in SuperOps](https://support.superops.com/en/articles/11583025-setting-up-requester-sso-in-superops)

**Related:** [SuperOpsIntegration.md](SuperOpsIntegration.md) · [Authentication.md](Authentication.md) · [Deployment.md](Deployment.md)

---

## What you are building

```
Client → On IT Portal (OAuth app #1)
      → clicks SuperOps
      → /integrations/superops/launch
      → SuperOps requester portal (SAML app #2)
      → Microsoft Entra ID
      → signed in as requester
```

| Entra enterprise app | Protocol | Purpose |
|---|---|---|
| **On IT Portal** | OAuth 2.0 / OIDC | Sign in to this Laravel portal (`MICROSOFT_CLIENT_ID`) |
| **SuperOps Requester SSO** | SAML | Sign in to `https://onitltd.superops.ai` as **requester** |

You need **two** Entra apps. Do **not** reuse the portal OAuth app for SuperOps SAML.

**Technician SSO:** separate Entra app and SuperOps **Technician Login → SSO** — see [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md). Do **not** reuse the requester SAML app.

---

## Prerequisites

- [ ] SuperOps MSP admin access (On IT tenant: subdomain **`onitltd`**)
- [ ] Entra ID **Cloud Application Administrator** (or Global Administrator)
- [ ] On IT Portal running with Entra login working ([Authentication.md](Authentication.md))
- [ ] Requester users exist in SuperOps with the **same email** as their On IT Portal account

---

## Part 1 — SuperOps (before Entra)

### 1.1 Confirm subdomain and custom domain

1. Sign in to SuperOps MSP console → **Settings → My Company → Branding**
2. Subdomain: **`onitltd`** → default URL `https://onitltd.superops.ai`
3. On IT also uses a **custom CNAME** for the requester portal: **`https://portal.onit.ltd`**

SAML uses the custom domain. On IT **Consumer Service URL** (Reply URL for Entra):

```
https://portal.onit.ltd/accounts-web/accounts/saml/response/5684471812792168448
```

Portal `.env`:

```env
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
```

### 1.2 Open Requester SSO settings

1. **Settings → Requester Login → SSO Protected**
2. Choose **Global SSO** (all requesters use one Entra app)
3. Keep this page open — you will copy values **to** and **from** here

> **Client-level SSO:** SuperOps product supports Client SSO, but **On IT does not use it**. All customers use **Global SSO** + per-tenant **admin Accept** of the multitenant Entra app (checklist step **08**).

### 1.3 Consumer Service URL (for Entra Reply URL)

From **Settings → Requester Login → SSO → Global SSO** → **Consumer Service URL**.

On IT value (confirmed 2026-06-12):

| Field | Value |
|---|---|
| **Consumer Service URL** | `https://portal.onit.ltd/accounts-web/accounts/saml/response/5684471812792168448` |

If you change CNAME or regenerate SSO in SuperOps, this URL may change — update Entra **Reply URL** to match.

---

## Part 2 — Microsoft Entra ID (SAML app)

### 2.1 Create enterprise application (platform — once)

1. [Azure Portal](https://portal.azure.com) → **Microsoft Entra ID**
2. **Enterprise applications** → **+ New application**
3. **+ Create your own application**
4. Name: `SuperOps Requester SSO (On IT)` (or similar — must be distinct from the portal app)
5. Select **Integrate any other application you don't find in the gallery (Non-gallery)**
6. **Create**

### 2.1a App Registration — **must be multitenant** (verified 2026-07-14)

SAML is configured on the **Enterprise application**. **Supported account types** and **Application ID URI** are on the linked **App registration** (same Application ID).

#### Two different URIs — do not mix them up

| Setting | Where in Entra | On IT value | Who requires it |
|---|---|---|---|
| **SAML Identifier (Entity ID)** | Enterprise app → **Single sign-on** → Basic SAML Configuration | `https://clientuser.superops.ai` | **SuperOps** Global SSO (fixed). Do **not** change this when enabling multitenant. |
| **Application ID URI** | App registration → **Expose an API** | `https://onit.ltd/superops-requester-sso` | **Microsoft** — multitenant apps must use a **tenant-verified domain** (e.g. `onit.ltd`). |

SuperOps docs tell you to set Entity ID to `https://clientuser.superops.ai`. That is correct for **SAML**. It is **wrong** as the App registration **Application ID URI** if you need multitenant (Azure will refuse the save).

#### Enable Multitenant (platform — once)

1. Entra → **App registrations** → **SuperOps Requester SSO (On IT)** (or Enterprise app → “manage additional properties on the application registration”)
2. **Expose an API** → **Application ID URI** → set to `https://onit.ltd/superops-requester-sso` (or `https://<tenant>.onmicrosoft.com/superops-requester-sso` if `onit.ltd` is not verified)
3. If a scope still starts with `https://clientuser.superops.ai/...`, update or remove it so scopes match the new App ID URI (SAML Global SSO does **not** need `user_impersonation`)
4. **Authentication** → Supported account types → **Multiple Entra ID tenants** → **Allow all tenants** → **Save**
5. Confirm Enterprise app → **Single sign-on** → Identifier (Entity ID) is still **`https://clientuser.superops.ai`**

**Verified save (2026-07-14):** Multitenant + Allow all tenants succeeded after App ID URI was moved to `https://onit.ltd/superops-requester-sso`.

**If Azure shows:** `Unable to update the Supported account type. The property Application ID URI … must be on a tenant verified domain` → you still have Application ID URI = `https://clientuser.superops.ai`. Fix Expose an API first, then retry Authentication.

**Default trap:** New apps are **Single tenant / My organization only**. That blocks customer `@company.com` users (“account does not exist in tenant On IT Technology Partners LTD”). Multitenant alone does not finish a client — each tenant still needs checklist **08 Accept**.

### 2.1b Verified Enterprise application Properties (On IT home, 2026-07-14)

| Property | Value |
|---|---|
| Name | SuperOps Requester SSO (On IT) |
| Application (client) ID | `bf1c303e-6015-43f7-abb2-5dfe8f67a5a1` |
| Object ID | `cc9c46a8-b038-4591-beab-414584233245` |
| App registration — Supported account types | **Multiple Entra ID tenants** / Allow all tenants |
| App registration — Application ID URI | `https://onit.ltd/superops-requester-sso` |
| SAML Identifier (Entity ID) | `https://clientuser.superops.ai` |
| Enabled for users to sign-in? | **Yes** |
| Assignment required? | **Yes** |
| Visible to users? | **Yes** |
| Reply URL | `https://portal.onit.ltd/accounts-web/accounts/saml/response/568447181…` |

Portal `.env`: `SUPEROPS_REQUESTER_SSO_CLIENT_ID=bf1c303e-6015-43f7-abb2-5dfe8f67a5a1` (Edit Client builds the Accept URL).

### 2.2 Assign users (use groups — not one-by-one)

Entra **Users and groups** controls who may use this SAML app. You do **not** need to add people individually.

**Recommended (any size company):**

1. Create an Entra **security group** per client or one shared group, e.g. `SuperOps Requesters` or `Client - Acme - Portal Users`
2. Add client staff to that group (or use a **dynamic group** rule, e.g. `department eq "Acme"`)
3. Enterprise app → **Users and groups** → **+ Add user/group** → **Groups** tab → select the group → **Assign**

One group assignment covers everyone in it (10 or 1,000 users). New joiners: add them to the group (or they match the dynamic rule) — no change to the SuperOps SAML app.

**Optional — open to whole tenant (use with care):**

Properties → **User assignment required?** → **No** → any user in the On IT tenant can attempt SAML sign-in. SuperOps still only accepts users who exist as **requesters** in SuperOps. Only use if you understand the security trade-off.

**Who must NOT be assigned:**

On IT **technicians** (MSP staff). This app is for **client requesters** only. Technicians use the MSP console / technician login separately — do not assign yourself (`tom.ashby@onit.ltd`) to test requester SSO.

**How to test without being a requester:**

1. Create a test requester in SuperOps (client user with a test email)
2. Create matching portal user in Admin → Users
3. Assign that user (or a **Test Requesters** group) to this Entra app
4. Sign in as **`portal.test@onit.ltd`** (private/incognito browser) — not `tom.ashby@onit.ltd`

### 2.3 Configure SAML

1. **Single sign-on** → **SAML**
2. **Edit** Basic SAML Configuration:

| Field | Value |
|---|---|
| **Identifier (Entity ID)** | `https://clientuser.superops.ai` |
| **Reply URL (ACS)** | `https://portal.onit.ltd/accounts-web/accounts/saml/response/5684471812792168448` |

3. Mark Entity ID as **default**; remove any other default identifier entries (per SuperOps docs)
4. **Save**

### 2.4 User attributes & claims

Click **Edit** on Attributes & Claims. Entra’s **defaults will not work** — SuperOps requires exact claim names.

**Remove or replace** default claims (`emailaddress`, `givenname`, `surname`, `name`, etc.) and add **only** these three (**case-sensitive**):

| Claim name | Source attribute |
|---|---|
| `email` | `user.userprincipalname` (or `user.mail` if mailbox exists) |
| `firstname` | `user.givenname` |
| `lastname` | `user.surname` |

Steps in Entra:
1. **Edit** → delete extra claims (keep only the three above, or add new ones with exact names)
2. For each row: **Claim name** = left column, **Source attribute** = right column
3. **Save**

SuperOps rejects login if claim names differ (e.g. `emailaddress` instead of `email`).

**Error 1027** (`#/error/ssoError?errorCode=1027` — “Email attribute missing; not configured in IDP”) means SAML completed but the assertion did **not** include a claim named exactly `email`. This is an **Entra configuration** issue, not a portal bug.

**Fix checklist:**

1. Entra → **SuperOps Requester SSO (On IT)** → **Single sign-on** → **Attributes & Claims** → **Edit**
2. Delete defaults (`emailaddress`, `givenname`, `surname`, `name`, duplicate identifiers)
3. Add **only** these three additional claims (names are case-sensitive):

| Claim name | Source attribute | If login still fails |
|---|---|---|
| `email` | `user.mail` | Try `user.userprincipalname` (test mailboxes often have no `mail`) |
| `firstname` | `user.givenname` | — |
| `lastname` | `user.surname` | — |

4. **Namespace (critical):** Edit each additional claim → **Namespace** must be **empty** (custom namespace with no URI), so the SAML attribute name is literally `email` — not `http://schemas.xmlsoap.org/.../emailaddress`. SuperOps Error 1027 often happens when Entra UI looks correct but the assertion uses the default URI namespace.
5. **Save** → wait 2–5 minutes → test in private window as `portal.test@onit.ltd`
6. Entra → **Users** → `portal.test@onit.ltd` → verify **Mail** is populated (or use `user.userprincipalname` for the `email` claim)

### Entra “Test single sign-on” vs real SuperOps login

| Method | How SAML starts | Expected result |
|---|---|---|
| Entra → **Test single sign-on** | **IdP-initiated** (Entra sends assertion without SuperOps `SAMLRequest`) | Often **Error 1028** — not a valid end-to-end test for requester portal |
| Portal → SuperOps → **Login as requester** | **SP-initiated** (SuperOps builds `SAMLRequest`, then Entra responds) | Correct test — should reach `/#/client-home` |

Do **not** use Entra Test as pass/fail for SuperOps requester SSO. Use the portal flow (or open `portal.onit.ltd` → **Login as requester** directly).

### 2.5 Certificate → SuperOps

1. Entra app → **SAML Certificates** → download **Certificate (Base64)**
2. Open in Notepad
3. Copy **only** the certificate body — **no** `-----BEGIN CERTIFICATE-----` / `-----END CERTIFICATE-----` lines
4. SuperOps → **Settings → Requester Login → SSO → Global SSO → Certificate** → paste → save

### 2.6 IDP Login URL → SuperOps (Step 2)

**Source:** Microsoft Entra — not invented by the portal. SuperOps docs: copy from Azure **Section 4** into SuperOps **IDP Login URL**.

| Step | Where | Action |
|---|---|---|
| 1 | Entra → **Enterprise applications** → **SuperOps Requester SSO (On IT)** | Open app |
| 2 | **Single sign-on** → **SAML** | Scroll to **Set up SuperOps Requester SSO (On IT)** |
| 3 | Copy **Login URL** | Format: `https://login.microsoftonline.com/{tenant-id}/saml2` |
| 4 | SuperOps → **Requester Login** → **SSO Protected** → **Global SSO** → **Step 2** | Paste into **IDP Login URL** |
| 5 | Click **Save** on the Global SSO panel | Toggle ON alone is not enough |

**On IT value (verify in Entra — use Entra if different):**

```
https://login.microsoftonline.com/586cc505-d298-4131-a3dc-9d1cd7c5ac0b/saml2
```

`586cc505-d298-4131-a3dc-9d1cd7c5ac0b` = On IT Entra **tenant ID** (same tenant as the SAML app).

### 2.7 Enable Global SSO in SuperOps

1. Enable **Global SSO** / **SSO Protected** for requester login
2. Confirm password login for requesters is disabled when Global SSO is on (expected)

### 2.8 Where each URL lives (do not mix up)

| URL | Copy from | Paste into | Never put in |
|---|---|---|---|
| **Consumer Service URL** | SuperOps Step 1 | Entra SAML **Reply URL** | Portal `.env` |
| **Entity ID** `https://clientuser.superops.ai` | SuperOps docs | Entra Enterprise app → SAML **Identifier** | Portal `.env` **or** App registration Application ID URI |
| **Application ID URI** `https://onit.ltd/superops-requester-sso` | On IT verified domain | App registration → **Expose an API** | SAML Entity ID (leave Entity ID alone) |
| **Login URL** `login.microsoftonline.com/.../saml2` | Entra Section 4 | SuperOps Step 2 **IDP Login URL** | Portal `.env` (causes `AADSTS750054`) |
| **Certificate (Base64)** | Entra SAML Certificates | SuperOps Step 2 **Certificate** | Portal `.env` |
| Requester portal | — | Portal `SUPEROPS_REQUESTER_PORTAL_URL` | Entra |

**Common mistake:** Global SSO toggle ON but Step 2 **empty** → requesters see **Login with Email** instead of Microsoft.

---

## Part 3 — On IT Portal `.env`

After SuperOps and Entra are linked, configure the portal:

```env
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_SSO_ENABLED=true
```

**Do not** set `SUPEROPS_SSO_URL` to the Entra Login URL. That URL is configured **inside SuperOps** (Global SSO → IDP Login URL). Putting it in portal `.env` causes error `AADSTS750054` (missing SAMLRequest).

Apply config:

```powershell
php artisan config:clear
```

### How the portal launch works

1. User clicks **SuperOps** on the dashboard
2. Portal redirects to `https://portal.onit.ltd/#/requester/login` (requester login — not the role chooser)
3. SuperOps (Service Provider) sends the user to Entra **with** a `SAMLRequest`
4. After Microsoft sign-in, user returns to SuperOps as a **requester**

Direct navigation to `https://login.microsoftonline.com/.../saml2` without a SAMLRequest will always fail.

---

## Provisioning — portal users, SuperOps requesters, Entra assignment

| System | What it controls | Scales how |
|---|---|---|
| **On IT Portal** (`users` table) | Who may sign in to the portal | `portal:sync-entra-users` + Admin exceptions |
| **SuperOps** (client users) | Who exists as a requester | Entra SCIM per client (`SuperOps - {Company}`) |
| **Entra** (SAML app assignment) | Who may use SuperOps Requester SSO | After **Accept**: P1 assigns `On IT Portal - {Company}` once; Free portal Sync now assigns every active licensed user directly |

### Multitenant Accept (required for every customer tenant — checklist **08**)

On IT uses **one** Global SSO SAML configuration in SuperOps. The Entra app is **multitenant**. Each of the **50+** customer tenants must **Accept** that app once.

The live Edit Client guide contains the complete per-customer acceptance procedure. The technician does this:

1. Open checklist **08**.
2. Click the orange **Open customer SuperOps SSO Accept page** button shown at the very top of the step. The button remains visible even after the checklist step is marked Done, so consent can be repeated or the link copied later.
3. Sign in as the **customer Global Admin** (not an `@onit.ltd` account).
4. On Microsoft’s permissions page, click **Accept**.
5. Customer Entra → **Enterprise applications → SuperOps Requester SSO (On IT) → Users and groups**:
   - **P1:** assign `On IT Portal - {Company}`.
   - **Entra ID Free:** do **not** add requester users manually. Mark step 08 complete, then portal **Sync now** directly assigns every active licensed customer user.
6. Return to the portal → tick **Mark this step complete** → **Save checklist**.
7. Complete steps 09–10. On Free, verify customer Entra → this app → **Users and groups** now lists all active licensed users.
8. Complete the real customer sign-in test later in checklist **11**.

**Accept URL:**

```
https://login.microsoftonline.com/{CUSTOMER-TENANT-ID}/adminconsent?client_id=bf1c303e-6015-43f7-abb2-5dfe8f67a5a1
```

**Not the same as checklist 04** (Portal Graph / `OnIT Portal for Portals`).

**Do not** use SuperOps **Client SSO** for normal onboardings.

**If Accept was skipped:** Microsoft error that the user is not in tenant **On IT Technology Partners LTD** / cannot access `https://clientuser.superops.ai`.

Platform recovery details (Entity ID, certificate and SuperOps Global SSO) stay in this Brain runbook. They must **not** be placed in routine checklist 08.

---

## Part 4 — Users and clients

### 4.1 SuperOps requesters

Each portal client user should exist in SuperOps as a **requester** with the **same email** (via SCIM after sync).

### 4.2 On IT Portal users

Admin → Clients — SuperOps Account ID; sync fills users. Manual Admin → Users only for exceptions.

### 4.3 Optional API sync

For embedded `/support` and automatic `superops_user_id` linking on login:

```env
SUPEROPS_API_TOKEN=...   # Settings → Your Profile → API Token
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REGION=us       # or eu
```

API token is **not** required for SSO launch alone.

---

## Part 5 — Test checklist

### After saving SuperOps Step 2 (IDP URL + certificate)

- [ ] SuperOps **Global SSO** toggle ON and **Save** clicked
- [ ] Step 2 fields not empty when you reload the page
- [ ] App registration **Supported account types** = Multiple Entra ID tenants / Allow all tenants
- [ ] App registration **Application ID URI** = `https://onit.ltd/superops-requester-sso` (not `clientuser.superops.ai`)
- [ ] Enterprise app SAML **Entity ID** still = `https://clientuser.superops.ai`

### Per customer tenant (every new client)

- [ ] Checklist **08** Accept completed
- [ ] P1: `On IT Portal - {Company}` assigned to requester SSO; Free: portal Sync now has assigned all active licensed users
- [ ] Incognito Microsoft login with customer work email succeeds

### SuperOps requester SSO (private browser)

Use a **customer** work email after Accept — or `portal.test@onit.ltd` for On IT home tenant tests only — **not** `tom.ashby@onit.ltd`.

- [ ] Open `https://portal.onit.ltd/#/requester/login` — requester login (not role chooser)
- [ ] Redirects to **Microsoft** (not “Login with Email”)
- [ ] Land in SuperOps **requester** dashboard (`/#/client-home`)

### On IT Portal (end-to-end)

- [ ] Private/incognito → https://app.onit.ltd/login → customer work email
- [ ] Dashboard → **SuperOps** — direct redirect to `/#/requester/login`
- [ ] Microsoft → requester dashboard

### Expected flow when working

```
Portal (Microsoft login as customer) → Dashboard → SuperOps
  → portal.onit.ltd/?login_hint=...#/requester/login
  → Microsoft (customer tenant session after Accept)
  → SuperOps requester dashboard (/#/client-home)
```

### Negative tests

- [ ] Accept skipped → “not in On IT tenant” / cannot access clientuser.superops.ai
- [ ] User in portal but **not** in SuperOps → SuperOps should deny or show error
- [ ] `tom.ashby@onit.ltd` via requester path → lands as **technician** (expected — do not use for requester SSO validation)

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Confused SuperOps Entity ID with Application ID URI | Put `clientuser.superops.ai` under Expose an API then tried Multitenant | Keep Entity ID on SAML blade; set App ID URI to `https://onit.ltd/superops-requester-sso`; retry Authentication |
| Unable to update Supported account type … Application ID URI must be on a tenant verified domain | App ID URI still on `clientuser.superops.ai` | §2.1a — change Expose an API first |
| Account does not exist in tenant **On IT Technology Partners LTD** | Customer tenant never Accepted the multitenant SAML app (or app still single-tenant) | Confirm Multitenant saved; then checklist **08** Accept URL with correct customer tenant ID |
| Chooser appears when using `/#/login` | Wrong launch path | Use `/#/requester/login` — not `/#/login/requester` |
| Tom Ashby lands as **technician** after requester click | Tom is MSP technician in SuperOps | Test with customer email or `portal.test@onit.ltd` |
| Wrong Microsoft account at SAML step | Browser cached Tom's M365 session | Private/incognito window |
| `Login with Email` on SuperOps after choosing requester | Step 2 empty or not saved in SuperOps | Re-paste IDP Login URL + cert from Entra; **Save** |
| **Error 1027** — “Email attribute missing; not configured in IDP” | Entra SAML claims wrong, or `user.mail` empty | Fix claims (§2.4); use `user.userprincipalname` if needed |
| **Error 1028** — on **Entra → Test single sign-on** only | Entra test is **IdP-initiated** | Ignore Entra Test; re-test via SuperOps SP-initiated flow |
| **Error 1028** on real SuperOps login | Certificate mismatch, expired cert, or wrong Reply URL | Re-download Entra cert → paste body only into SuperOps Step 2 → **Save** |
| Assignment required but sign-in denied | Group/user not assigned on customer SP after Accept | Customer Entra → Users and groups → assign Portal group |
| Confused with Portal Graph consent | Step 04 vs step 08 | Different Application IDs; both Accepts are required |
| Step 2 blank after reload | Did not click Save | Fill IDP Login URL, Certificate; Save Global SSO panel |
| `AADSTS750054` SAMLRequest must be present | Entra Login URL in portal `.env` | Remove from `SUPEROPS_SSO_URL`; portal redirects to `portal.onit.ltd` only |
| CNAME change after SSO | SuperOps docs warn URLs break | Update Entra Reply URL and SuperOps settings |

---

## Production (Plesk)

Same steps; update production `.env`:

```env
APP_URL=https://app.onit.ltd
MICROSOFT_REDIRECT_URI=https://app.onit.ltd/auth/microsoft/callback
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_SSO_CLIENT_ID=bf1c303e-6015-43f7-abb2-5dfe8f67a5a1
# SUPEROPS_SSO_URL=   ← leave empty
```

Entra SAML Reply URL and portal OAuth redirect URI are **independent** — changing `APP_URL` does not affect SuperOps SAML.

See [Deployment.md](Deployment.md) for full Plesk checklist.

---

## What we deliberately skip

| Item | Reason |
|---|---|
| SuperOps **Client SSO** per customer | Operating model = Global SSO + multitenant Accept |
| Technician SSO Entra app for this checklist | Not needed for client portal requesters |
| Reusing portal OAuth app | Wrong protocol (OIDC vs SAML) and wrong redirect URLs |
| `app.superops.ai` as requester target | MSP technician app — not the requester portal |

---

## Quick reference — On IT values

| Item | Value |
|---|---|
| Entra app name | SuperOps Requester SSO (On IT) |
| Entra Application (client) ID | `bf1c303e-6015-43f7-abb2-5dfe8f67a5a1` |
| Entra Object ID | `cc9c46a8-b038-4591-beab-414584233245` |
| Supported account types | **Multiple Entra ID tenants** / Allow all tenants (verified 2026-07-14) |
| Application ID URI (Expose an API) | `https://onit.ltd/superops-requester-sso` |
| Assignment required? | **Yes** |
| Entra tenant ID (home) | `586cc505-d298-4131-a3dc-9d1cd7c5ac0b` |
| Entra SAML Login URL | `https://login.microsoftonline.com/586cc505-d298-4131-a3dc-9d1cd7c5ac0b/saml2` |
| Requester portal (custom domain) | https://portal.onit.ltd |
| Requester portal (default) | https://onitltd.superops.ai |
| Consumer Service URL (Entra Reply URL) | `https://portal.onit.ltd/accounts-web/accounts/saml/response/5684471812792168448` |
| SuperOps Entity ID (SAML Identifier — not App ID URI) | `https://clientuser.superops.ai` |
| Portal Accept URL env | `SUPEROPS_REQUESTER_SSO_CLIENT_ID` |
| Portal launch route | `/integrations/superops/launch` |
| SuperOps SSO setting path | Settings → Requester Login → SSO Protected → Global SSO |
| Checklist step | **08** — Customer Accepts SuperOps login |

---

## Change log

| Date | Author | Notes |
|---|---|---|
| 2026-07-14 | On IT | Entra ID Free: portal Sync now auto-assigns all active licensed users after customer Accept; no manual user assignment |
| 2026-07-14 | On IT | Multitenant saved: App ID URI `https://onit.ltd/superops-requester-sso` vs SAML Entity ID `https://clientuser.superops.ai` documented; Accept path for 50+ clients |
| 2026-07-14 | On IT | Documented multitenant Accept for every customer; verified Properties; removed Client SSO operating path |
| 2026-06-15 | On IT | Phase A validated; launch path `/#/requester/login`; Entra errors 1027/1028 documented |
| 2026-06-12 | On IT | Added On IT Consumer Service URL (`portal.onit.ltd` CNAME) |
| 2026-06-12 | On IT | Initial guide — requester SSO only, Global SSO, `onitltd` subdomain |
