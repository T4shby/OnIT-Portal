# SuperOps Technician SSO — Setup Guide (On IT)

Step-by-step guide to enable **Microsoft Entra ID SSO for SuperOps technicians** (MSP staff). This is **separate** from requester SSO — SuperOps requires a **different** Entra enterprise app and SuperOps admin settings.

**Official SuperOps reference:** [Setting up Technician SSO with Azure AD](https://support.superops.com/en/articles/6632446-setting-up-technician-sso-with-azure-ad)

**Related:** [SuperOpsIntegration.md](SuperOpsIntegration.md) · [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) · [Authentication.md](Authentication.md) · [Deployment.md](Deployment.md)

---

## What you are building

```
Technician → On IT Portal (OAuth app #1)
          → clicks SuperOps
          → /integrations/superops/launch
          → SuperOps technician login SPA (same host as requester)
          → Microsoft Entra ID (SAML app #3)
          → signed in as technician
```

| Entra enterprise app | Protocol | Purpose |
|---|---|---|
| **On IT Portal** | OAuth 2.0 / OIDC | Sign in to this Laravel portal (`MICROSOFT_CLIENT_ID`) |
| **SuperOps Requester SSO (On IT)** | SAML | Client users → `portal.onit.ltd` as **requester** |
| **SuperOps Technician SSO (On IT)** | SAML | On IT staff → `portal.onit.ltd` as **technician** |

You need **three** Entra apps for full portal + SuperOps SSO. Do **not** reuse the requester SAML app for technicians — SuperOps explicitly requires separate apps.

**Portal role:** The Laravel app **cannot** perform SAML. It redirects to the SuperOps SPA (`/#/technician/login`), which initiates SP-initiated SAML to Entra — same pattern as requester SSO.

---

## Prerequisites

- [ ] SuperOps MSP admin access (On IT tenant: subdomain **`onitltd`**)
- [ ] Entra ID **Cloud Application Administrator** (or Global Administrator)
- [ ] On IT Portal running with Entra login working ([Authentication.md](Authentication.md))
- [ ] Requester SSO already working or in progress ([SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md))
- [ ] Technician users exist in SuperOps with the **same email** as their On IT Portal account

---

## Part 1 — SuperOps (before Entra)

### 1.1 Confirm launch host

On IT launches technicians to the **same SuperOps host** as requesters (custom CNAME), with a different SPA path:

| Audience | Launch URL |
|---|---|
| Requester (clients) | `https://portal.onit.ltd/#/requester/login` |
| Technician (On IT staff) | `https://portal.onit.ltd/#/technician/login` |

Do **not** launch technicians to `https://app.superops.ai` — that host does not share the custom-domain Technician Login SSO configuration.

Portal `.env`:

```env
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
# Optional override — defaults to same host as requester:
# SUPEROPS_TECHNICIAN_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_TECHNICIAN_LOGIN_PATH=/#/technician/login
```

### 1.2 Open Technician SSO settings

1. Sign in to SuperOps MSP console
2. **Settings → Technician Login → SSO**
3. Keep this page open — you will copy values **to** and **from** here

> Technician Login SSO is **independent** of **Settings → Requester Login → SSO Protected → Global SSO**. Configuring requester SSO does **not** enable technician SSO.

### 1.3 Consumer Service URL (for Entra Reply URL)

From **Settings → Technician Login → SSO** → **Consumer Service URL**.

Copy the exact value shown in SuperOps — it is tenant-specific. On IT uses custom domain `portal.onit.ltd`; the path will resemble:

```
https://portal.onit.ltd/accounts-web/accounts/saml/response/{id}
```

If you change CNAME or regenerate SSO in SuperOps, this URL may change — update Entra **Reply URL** to match.

---

## Part 2 — Microsoft Entra ID (SAML app)

### 2.1 Create enterprise application

1. [Azure Portal](https://portal.azure.com) → **Microsoft Entra ID**
2. **Enterprise applications** → **+ New application**
3. **+ Create your own application**
4. Name: `SuperOps Technician SSO (On IT)` (must be distinct from portal OAuth and requester SAML apps)
5. Select **Integrate any other application you don't find in the gallery (Non-gallery)**
6. **Create**

### 2.2 Assign users (use groups)

Entra **Users and groups** controls who may use this SAML app.

**Recommended:**

1. Create an Entra **security group**, e.g. `SuperOps Technicians` or `On IT - MSP Staff`
2. Add On IT technicians (e.g. `tom.ashby@onit.ltd`)
3. Enterprise app → **Users and groups** → **+ Add user/group** → **Groups** tab → select the group → **Assign**

**Who must NOT be assigned:**

Client **requesters**. This app is for **MSP technicians** only. Do not assign `portal.test@onit.ltd` here — that user tests requester SSO with the **requester** SAML app.

### 2.3 Configure SAML

1. **Single sign-on** → **SAML**
2. **Edit** Basic SAML Configuration:

| Field | Value |
|---|---|
| **Identifier (Entity ID)** | `https://superops.ai` |
| **Reply URL (ACS)** | Consumer Service URL from SuperOps §1.3 |

3. Mark Entity ID as **default**; remove any other default identifier entries (per SuperOps docs)
4. **Save**

> **Requester vs technician Entity ID:** Requester SSO uses `https://clientuser.superops.ai`. Technician SSO uses `https://superops.ai`. They are **not** interchangeable.

### 2.4 User attributes & claims

Click **Edit** on Attributes & Claims. SuperOps requires exact claim names — Entra defaults will not work.

**Remove or replace** default claims and add **only** these three (**case-sensitive**):

| Claim name | Source attribute |
|---|---|
| `email` | `user.mail` (or `user.userprincipalname` if mailbox empty) |
| `firstname` | `user.givenname` |
| `lastname` | `user.surname` |

Steps in Entra:
1. **Edit** → delete extra claims (`emailaddress`, `givenname`, `surname`, `name`, etc.)
2. For each row: **Claim name** = left column, **Source attribute** = right column
3. **Namespace:** Edit each additional claim → **Namespace** must be **empty** (custom namespace with no URI), so the SAML attribute name is literally `email`
4. **Save**

**Error 1027** (`Email attribute missing`) means the assertion did not include a claim named exactly `email` — fix Entra claims, not portal code.

### 2.5 Certificate → SuperOps

1. Entra app → **SAML Certificates** → download **Certificate (Base64)**
2. Open in Notepad
3. Copy **only** the certificate body — **no** `-----BEGIN CERTIFICATE-----` / `-----END CERTIFICATE-----` lines
4. SuperOps → **Settings → Technician Login → SSO → Certificate** → paste → **Save**

### 2.6 IDP Login URL → SuperOps

| Step | Where | Action |
|---|---|---|
| 1 | Entra → **SuperOps Technician SSO (On IT)** | Open app |
| 2 | **Single sign-on** → **SAML** | Scroll to **Set up SuperOps Technician SSO (On IT)** |
| 3 | Copy **Login URL** | Format: `https://login.microsoftonline.com/{tenant-id}/saml2` |
| 4 | SuperOps → **Technician Login → SSO** | Paste into **IDP Login URL** |
| 5 | Click **Save** | Toggle alone is not enough |

**On IT tenant ID (verify in Entra):**

```
https://login.microsoftonline.com/586cc505-d298-4131-a3dc-9d1cd7c5ac0b/saml2
```

### 2.7 Enable Technician SSO in SuperOps

1. Enable **SSO** for technician login in SuperOps admin
2. Confirm **IDP Login URL** and **Certificate** are saved (reload page to verify)
3. When SSO is on, password login for technicians may be disabled (expected)

### 2.8 Where each URL lives (do not mix up)

| URL | Copy from | Paste into | Never put in |
|---|---|---|---|
| **Consumer Service URL** | SuperOps Technician Login SSO | Entra SAML **Reply URL** | Portal `.env` |
| **Entity ID** `https://superops.ai` | SuperOps docs | Entra SAML **Identifier** | Portal `.env` |
| **Login URL** `login.microsoftonline.com/.../saml2` | Entra Section 4 | SuperOps **IDP Login URL** | Portal `.env` (causes `AADSTS750054`) |
| **Certificate (Base64)** | Entra SAML Certificates | SuperOps **Certificate** | Portal `.env` |
| Technician launch host | — | `SUPEROPS_REQUESTER_PORTAL_URL` (or `SUPEROPS_TECHNICIAN_PORTAL_URL`) | Entra |

---

## Part 3 — On IT Portal `.env`

After SuperOps and Entra are linked:

```env
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_TECHNICIAN_LOGIN_PATH=/#/technician/login
SUPEROPS_LOGIN_HINT_ENABLED=true
SUPEROPS_SSO_ENABLED=true
```

**Do not** set `SUPEROPS_SSO_URL` to the Entra Login URL. That URL belongs in **SuperOps admin only**.

`SUPEROPS_PORTAL_URL` (`https://app.superops.ai`) is **not** used for technician SSO launch — it is legacy/reference only.

Apply config:

```powershell
php artisan config:clear
```

### How the portal launch works

1. Technician (`super_admin` or `account_manager`) clicks **SuperOps** on the dashboard
2. Portal redirects to `https://portal.onit.ltd/?login_hint=…#/technician/login`
3. SuperOps (Service Provider) sends the user to Entra **with** a `SAMLRequest`
4. After Microsoft sign-in, user returns to SuperOps as a **technician**

Direct navigation to `https://login.microsoftonline.com/.../saml2` without a SAMLRequest will always fail (`AADSTS750054`).

---

## Part 4 — Users

### 4.1 SuperOps technicians

Each On IT portal team user must exist in SuperOps as an **MSP technician** with the **same email** as `users.email` in the portal.

1. SuperOps → **Settings → Users** (or team management) → confirm technician exists
2. Email must match exactly (e.g. `tom.ashby@onit.ltd`)

### 4.2 On IT Portal users

1. Admin → **Users** — role `super_admin` or `account_manager`
2. Email must match SuperOps technician account

### 4.3 Entra assignment

Add technicians to `SuperOps Technicians` group (or assign directly) on the **Technician SSO** enterprise app — **not** the requester SAML app.

---

## Part 5 — Test checklist

### After saving SuperOps Technician SSO (IDP URL + certificate)

- [ ] SuperOps Technician Login **SSO** toggle ON and **Save** clicked
- [ ] IDP Login URL and Certificate not empty when you reload the page

### SuperOps technician SSO (private browser)

Use **`tom.ashby@onit.ltd`** (`super_admin`) — not `portal.test@onit.ltd` (requester).

- [ ] Open `https://portal.onit.ltd/#/technician/login` — technician login (not role chooser)
- [ ] Redirects to **Microsoft** (not “Login with Email” / password form)
- [ ] At Microsoft, confirm **`tom.ashby@onit.ltd`**
- [ ] Land in SuperOps **technician** MSP console

### On IT Portal (end-to-end)

- [ ] `php artisan config:clear` (no Entra URL in `SUPEROPS_SSO_URL`)
- [ ] Private/incognito → https://app.onit.ltd/login → **`tom.ashby@onit.ltd`**
- [ ] Dashboard → **SuperOps** — direct redirect to `portal.onit.ltd/?login_hint=…#/technician/login`
- [ ] Microsoft → technician MSP console

### Expected flow when working

```
Portal (Microsoft login once as tom.ashby@onit.ltd) → Dashboard → SuperOps
  → portal.onit.ltd/?login_hint=...#/technician/login
  → Microsoft (session may be silent)
  → SuperOps technician MSP console
```

### Negative tests

- [ ] Launch to `app.superops.ai/#/technician/login` → SSO fails or shows email/password (wrong host)
- [ ] Technician not assigned to Technician SAML app → Entra denies or SuperOps error
- [ ] `portal.test@onit.ltd` via technician path → should not land as technician (wrong user for this test)

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Role chooser appears | Wrong launch path | Use `/#/technician/login` — not `/#/login` |
| `Login with Email` on technician login | Technician SSO not configured or Step 2 empty | Configure **Technician Login → SSO** in SuperOps; paste IDP URL + cert |
| Redirect to `app.superops.ai` | Stale portal config or old code | Set `SUPEROPS_REQUESTER_PORTAL_URL`; `config:clear`; deploy latest portal |
| **Error 1027** — email missing | Entra claims wrong | Fix claims (§2.4); use `user.userprincipalname` if no mailbox |
| **Error 1028** on Entra Test SSO only | IdP-initiated test | Ignore — test via portal or `/#/technician/login` (SP-initiated) |
| **Error 1028** on real login | Cert mismatch or wrong Reply URL | Re-download Entra cert; verify Consumer Service URL |
| `AADSTS750054` | Entra Login URL in portal `.env` | Remove from `SUPEROPS_SSO_URL` |
| Portal shows configuration error | Missing `SUPEROPS_SUBDOMAIN` / requester URL | Set `.env`; `config:clear` |
| SAML works but access denied | User not a SuperOps technician | Provision technician in SuperOps with matching email |
| Requester SSO breaks after technician setup | Reused same Entra app | Separate apps — requester Entity ID `clientuser.superops.ai`, technician `superops.ai` |

---

## Production (Plesk)

Same steps; update production `.env` on **`app.onit.ltd`**:

```env
APP_URL=https://app.onit.ltd
MICROSOFT_REDIRECT_URI=https://app.onit.ltd/auth/microsoft/callback
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_TECHNICIAN_LOGIN_PATH=/#/technician/login
SUPEROPS_SSO_ENABLED=true
```

After Git pull: `php artisan config:clear` and `php artisan optimize`.

See [Deployment.md](Deployment.md) for full Plesk checklist.

---

## Quick reference — On IT values

| Item | Value |
|---|---|
| Entra app name | SuperOps Technician SSO (On IT) |
| Entra tenant ID | `586cc505-d298-4131-a3dc-9d1cd7c5ac0b` |
| SuperOps Entity ID (Entra) | `https://superops.ai` |
| Technician launch host | https://portal.onit.ltd |
| Technician launch path | `/#/technician/login` |
| Portal launch route | `/integrations/superops/launch` |
| SuperOps SSO setting path | Settings → Technician Login → SSO |
| Test user | `tom.ashby@onit.ltd` (`super_admin`) |

> Consumer Service URL and Entra app IDs are copied from SuperOps / Entra admin at setup time — do not hard-code in portal `.env`.

---

## Change log

| Date | Author | Notes |
|---|---|---|
| 2026-06-22 | On IT | Initial guide — technician SAML app #3, same host as requester, portal launch path |
