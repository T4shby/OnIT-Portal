# SuperOps Technician SSO — Setup Guide (On IT)

Copy the **requester SSO** process ([SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md)) — same steps, **different values** and a **different Entra app**. Technicians and requesters share the same Microsoft tenant; SuperOps requires separate SAML registrations.

**Official SuperOps reference:** [Setting up Technician SSO with Azure AD](https://support.superops.com/en/articles/6632446-setting-up-technician-sso-with-azure-ad)

**Related:** [SuperOpsIntegration.md](SuperOpsIntegration.md) · [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) · [OperatorRunbook.md](OperatorRunbook.md) (Phase A0)

---

## Why a second Entra app? (requester vs technician)

| | **Requester SSO** (already done) | **Technician SSO** (this guide) |
|---|---|---|
| **Who** | Client staff (`portal.test@onit.ltd`) | On IT staff (`tom.ashby@onit.ltd`) |
| **SuperOps screen** | Settings → **Requester Login** → SSO Protected → Global SSO | Settings → **Technician Login** → SSO |
| **Entra app name** | SuperOps Requester SSO (On IT) | **SuperOps Technician SSO (On IT)** |
| **Entity ID** | `https://clientuser.superops.ai` | `https://superops.ai` |
| **Reply URL (On IT)** | `https://portal.onit.ltd/accounts-web/accounts/saml/response/5684471812792168448` | `https://usauth.superops.ai/api/federated_auth/saml/response/5684471812792168448` |
| **Portal launch path** | `/#/requester/login` | `/#/technician/login` |

Same IdP Login URL format, same three claims (`email`, `firstname`, `lastname`), same cert copy/paste workflow — **different Reply URL and Entity ID**. One Entra app cannot use the requester Reply URL for technician login.

---

## Master checklist (tick as you go)

### Part A — SuperOps (5 min)

- [ ] **A1** Open **Settings → Technician Login → SSO** tab (not Requester Login)
- [ ] **A2** Turn **Global SSO Configurations** ON
- [ ] **A3** Copy **Consumer Service URL** from Step 1 (save in Notepad)
- [ ] **A4** Leave Step 2 empty for now — fill after Entra (§2.6–2.7)

### Part B — Entra (20 min)

- [ ] **B1** Create enterprise app `SuperOps Technician SSO (On IT)` — non-gallery
- [ ] **B2** Single sign-on → **SAML**
- [ ] **B3** Basic SAML: Entity ID `https://superops.ai` + Reply URL from A3
- [ ] **B4** Attributes & claims: only `email`, `firstname`, `lastname`
- [ ] **B5** Create group `SuperOps Technicians`, add On IT staff, assign to app
- [ ] **B6** Download SAML certificate (Base64)

### Part C — SuperOps again (5 min)

- [ ] **C1** Paste **IDP Login URL** from Entra into SuperOps Step 2
- [ ] **C2** Paste **certificate** (body only, no BEGIN/END lines) into SuperOps Step 2
- [ ] **C3** Click **Save** — reload page and confirm both fields still populated

### Part D — Test

- [ ] **D1** Private window → `https://portal.onit.ltd/#/technician/login` → **Microsoft** (not email form)
- [ ] **D2** Portal → `https://app.onit.ltd` → login as `tom.ashby@onit.ltd` → **SuperOps** tile → technician console

---

## Part 1 — SuperOps (before Entra)

### Step A1 — Open Technician SSO (not Requester)

1. Sign in to SuperOps MSP console
2. **Settings → Technician Login**
3. Click tab **SSO PROTECTED** (not Password Protected)

> **Requester SSO** lives under **Requester Login**. Turning that on does **not** configure technician SSO.

### Step A2 — Enable Global SSO toggle

1. Under **Global SSO Configurations**, switch **ON** (green)
2. Do **not** click Save yet if Step 2 is still empty — you will Save after §2.7

### Step A3 — Copy Consumer Service URL

From **Step 1: Consumer service URL**, copy the full **Consumer Service URL**.

**On IT value (confirmed 2026-06-22):**

```
https://usauth.superops.ai/api/federated_auth/saml/response/5684471812792168448
```

> This is **not** the same URL as requester SSO (`portal.onit.ltd/accounts-web/...`). Paste this exact string into Entra Reply URL in §2.3.

SuperOps also reminds you: configure attributes **`email`**, **`firstname`**, **`lastname`** in Entra.

### Step A4 — Portal launch host (no SuperOps change)

On IT portal redirects technicians to:

```
https://portal.onit.ltd/#/technician/login
```

Production `.env` (on `app.onit.ltd`):

```env
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_TECHNICIAN_LOGIN_PATH=/#/technician/login
SUPEROPS_SSO_ENABLED=true
```

---

## Part 2 — Microsoft Entra ID (SAML app #3)

Mirror [SuperOpsRequesterSsoSetup.md §2](SuperOpsRequesterSsoSetup.md) — same clicks, technician values below.

### Step B1 — Create enterprise application ← **YOU ARE HERE**

1. Open [Azure Portal](https://portal.azure.com) → **Microsoft Entra ID**
2. **Enterprise applications** → **+ New application**
3. Click **+ Create your own application**
4. **What's the name of your app?** → `SuperOps Technician SSO (On IT)`
5. **What are you looking to do?** → select:
   - **Integrate any other application you don't find in the gallery (Non-gallery)**
   - Do **not** select "Register an application to integrate with Microsoft Entra ID" (that is for OAuth apps like the portal)
6. Click **Create**

You should land on the new app's **Overview** page.

### Step B2 — Start SAML setup

1. Left menu → **Single sign-on**
2. Click **SAML** (only SAML tile)
3. You are now on **Set up Single Sign-On with SAML**

### Step B3 — Basic SAML configuration

1. Click **Edit** on **Basic SAML Configuration**
2. Fill in:

| Field | Value |
|---|---|
| **Identifier (Entity ID)** | `https://superops.ai` |
| **Reply URL (Assertion Consumer Service URL)** | `https://usauth.superops.ai/api/federated_auth/saml/response/5684471812792168448` |

3. Mark `https://superops.ai` as **default** identifier
4. Remove any other default identifier rows (per SuperOps docs)
5. **Save**

Compare to requester app (do not mix up):

| App | Entity ID | Reply URL |
|---|---|---|
| Requester | `https://clientuser.superops.ai` | `https://portal.onit.ltd/accounts-web/accounts/saml/response/5684471812792168448` |
| **Technician** | `https://superops.ai` | `https://usauth.superops.ai/api/federated_auth/saml/response/5684471812792168448` |

### Step B4 — User attributes & claims

1. **Attributes & Claims** → **Edit**
2. Delete default claims (`emailaddress`, `givenname`, `surname`, `name`, etc.)
3. Add **only** these three (**case-sensitive** names):

| Claim name | Source attribute |
|---|---|
| `email` | `user.mail` (or `user.userprincipalname` if no mailbox) |
| `firstname` | `user.givenname` |
| `lastname` | `user.surname` |

4. For each claim: **Edit** → **Namespace** = **empty** (no URI prefix)
5. **Save**

Same as requester SSO — see [SuperOpsRequesterSsoSetup.md §2.4](SuperOpsRequesterSsoSetup.md) if you get **Error 1027**.

### Step B5 — Assign technicians (groups)

1. Left menu → **Users and groups** → **+ Add user/group**
2. Create security group **`SuperOps Technicians`** (if not exists) → add `tom.ashby@onit.ltd` and other On IT staff
3. **Groups** tab → select `SuperOps Technicians` → **Assign**

**Do not assign:**

- `portal.test@onit.ltd` (requester test user — belongs on **Requester** SAML app only)
- Client customer users

### Step B6 — Copy Login URL and certificate

Still on **Single sign-on → SAML**, scroll to **Set up SuperOps Technician SSO (On IT)**:

1. Copy **Login URL** — On IT format:

```
https://login.microsoftonline.com/586cc505-d298-4131-a3dc-9d1cd7c5ac0b/saml2
```

2. Under **SAML Certificates** → download **Certificate (Base64)**
3. Open in Notepad → copy **only** the certificate characters (no `-----BEGIN CERTIFICATE-----` lines)

Keep both in Notepad for Part C.

> **Ignore Entra "Test single sign-on"** if it shows Error 1028 — same as requester guide. Test via `portal.onit.ltd/#/technician/login` instead.

---

## Part 3 — SuperOps (finish Step 2)

Return to **Settings → Technician Login → SSO**.

### Step C1 — IDP Login URL

1. **Step 2: Identity provider configuration**
2. **IDP Login URL** → paste Login URL from B6
3. Example: `https://login.microsoftonline.com/586cc505-d298-4131-a3dc-9d1cd7c5ac0b/saml2`

### Step C2 — Certificate

1. **Certificate** field → paste certificate body from B6 (no BEGIN/END markers)

### Step C3 — Save and verify

1. Click purple **Save** (top right)
2. **Reload the page**
3. Confirm **IDP Login URL** and **Certificate** are still populated (not blank)
4. Confirm **Global SSO Configurations** still ON

If Step 2 was empty when you tested before, you would see `usauth.superops.ai` **Login with Email** — that is fixed once C1–C3 are saved.

---

## Part 4 — Portal `.env` (app.onit.ltd)

```env
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_TECHNICIAN_LOGIN_PATH=/#/technician/login
SUPEROPS_LOGIN_HINT_ENABLED=true
SUPEROPS_SSO_ENABLED=true
```

**Never** put the Entra Login URL in `SUPEROPS_SSO_URL` (causes `AADSTS750054`).

After deploy or `.env` change:

```bash
php artisan config:clear
php artisan optimize
```

### How portal launch works

```
tom.ashby@onit.ltd → app.onit.ltd/dashboard → SuperOps tile
  → /integrations/superops/launch
  → portal.onit.ltd/?login_hint=tom.ashby@onit.ltd#/technician/login
  → SuperOps sends SAMLRequest → Microsoft
  → SuperOps technician MSP console
```

---

## Part 5 — Test

### Direct SuperOps test (private/incognito)

1. Open `https://portal.onit.ltd/#/technician/login`
2. Expect redirect to **Microsoft** — not `usauth.superops.ai` email/password form
3. Sign in as **`tom.ashby@onit.ltd`**
4. Land in SuperOps **technician** console

### End-to-end via portal

1. `https://app.onit.ltd/login` → `tom.ashby@onit.ltd`
2. Dashboard → **SuperOps**
3. Same Microsoft → technician console flow

---

## Workaround (until Part C is done)

If you need technician access **before** Technician SSO is saved, use the **working requester SAML** path temporarily:

```env
SUPEROPS_TECHNICIAN_LOGIN_PATH=/#/requester/login
```

SuperOps maps `tom.ashby@onit.ltd` to technician even on the requester path. Switch back to `/#/technician/login` after Part C is complete.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Login with Email` on `usauth.superops.ai` | Step 2 empty in SuperOps Technician SSO | Complete Part C (IDP URL + cert + Save) |
| Redirect to Microsoft then error | Wrong Reply URL or cert | Re-check B3 values against SuperOps Consumer Service URL |
| **Error 1027** | Wrong Entra claims | Fix B4 (namespace empty, names `email`/`firstname`/`lastname`) |
| **Error 1028** on Entra Test only | IdP-initiated test | Ignore — test via `/#/technician/login` |
| Requester SSO breaks | Reused requester Entra app | Separate apps — see comparison table at top |
| `AADSTS750054` | Entra Login URL in portal `.env` | Remove from `SUPEROPS_SSO_URL` |

---

## Quick reference — On IT values

| Item | Value |
|---|---|
| Entra app name | SuperOps Technician SSO (On IT) |
| Entra tenant ID | `586cc505-d298-4131-a3dc-9d1cd7c5ac0b` |
| SAML Login URL | `https://login.microsoftonline.com/586cc505-d298-4131-a3dc-9d1cd7c5ac0b/saml2` |
| Entity ID (Entra) | `https://superops.ai` |
| Reply URL (Entra) | `https://usauth.superops.ai/api/federated_auth/saml/response/5684471812792168448` |
| SuperOps settings path | Settings → Technician Login → SSO |
| Technician launch URL | `https://portal.onit.ltd/#/technician/login` |
| Test user | `tom.ashby@onit.ltd` |
| Entra group | `SuperOps Technicians` |

---

## Change log

| Date | Notes |
|---|---|
| 2026-06-22 | Added master checklist, requester comparison, On IT `usauth` Consumer URL, step B1–C3 aligned to screenshots |
| 2026-06-22 | Initial guide |
