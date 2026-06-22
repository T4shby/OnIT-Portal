# Pax8 Enterprise SSO — Setup Guide (On IT)

Step-by-step guide to enable **Microsoft Entra ID federation for Pax8 partner (technician) login**. The On IT Portal launches technicians to Pax8 with `login_hint`; Pax8 Enterprise SSO completes Microsoft authentication.

**Official Pax8 reference:** [Enterprise SSO PDF](https://www.pax8nebula.com/m/10eadb52f582df44/original/Enterprise-SSO.pdf) (May 2021 — same workflow as Pax8 “Enterprise SSO v2”)

**Related:** [Pax8Integration.md](Pax8Integration.md) · [OperatorRunbook.md](OperatorRunbook.md) (Phase A2) · [AccessAndSync.md](AccessAndSync.md)

---

## What you are building

```
Technician → On IT Portal (OAuth app #1)
          → clicks Pax8
          → /integrations/pax8/launch
          → https://app.pax8.com/login?login_hint=tom.ashby@onit.ltd
          → Auth0 identifier (email pre-filled) → Continue
          → Microsoft Entra (Pax8 Enterprise SSO federation)
          → Pax8 partner dashboard
```

| Layer | Who configures | What |
|---|---|---|
| **Portal** | Developer / ops | `.env` launch URLs — already implemented |
| **Pax8** | **Primary Partner Admin** | Enterprise SSO → Azure AD, DNS TXT, Finalize |
| **Entra** | Global Admin (once) | Consent to Pax8 app on first federated login |
| **Pax8 users** | Partner Admin | Each technician is a **Pax8 app user** with UPN matching Microsoft |

**No separate Entra SAML enterprise app** — unlike SuperOps, Pax8 owns the Azure AD federation inside Pax8 admin. The portal only opens `app.pax8.com` with `login_hint`.

**Customer (self-service) SSO:** not available per Pax8 docs. Client users use company deep link + `login_hint` until Pax8 ships customer IdP SSO.

---

## Requirements (from Pax8)

- [ ] Azure AD / Entra ID tenant with a **verified domain**
- [ ] **Global Administrator** in Entra (first consent only)
- [ ] **Primary Partner Admin** in Pax8 (only role that sees Enterprise SSO tab)
- [ ] **UserPrincipalNames** in Microsoft match Pax8 **login username** exactly
- [ ] Each technician exists as a **Pax8 app user** before they federate

> **WARNING (Pax8):** If Microsoft UPN ≠ Pax8 username, federated login fails and you may need Pax8 support. Create app users with matching emails **before** Finalize.

---

## Master checklist (tick as you go)

### Part A — Pax8 app users (before SSO)

- [ ] **A1** Pax8 → **Users** → confirm each technician exists (e.g. `tom.ashby@onit.ltd`)
- [ ] **A2** UPN in Entra matches Pax8 username exactly (check Entra → Users → user → UPN)
- [ ] **A3** Notify staff they will sign in with Microsoft on next Pax8 login

### Part B — Enterprise SSO in Pax8 (Primary Partner Admin)

- [ ] **B1** **Admin → My Partner Profile → Enterprise SSO → Azure AD**
- [ ] **B2** **Email Domain:** primary Azure domain (On IT: `onit.ltd`)
- [ ] **B3** **Domain Aliases:** any other UPN suffixes in use (or leave blank)
- [ ] **B4** Click **Create**
- [ ] **B5** Add DNS **TXT** record(s) at domain registrar → wait propagation
- [ ] **B6** Click **Verify Domain** → status **Verified**
- [ ] **B7** Click **Finalize**

### Part C — Portal production `.env`

- [ ] **C1** `PAX8_PARTNER_PORTAL_URL=https://app.pax8.com` (required — not `mycommandconsole.com`)
- [ ] **C2** `PAX8_PARTNER_LOGIN_PATH=/login`
- [ ] **C3** Deploy + `php artisan config:clear` + `optimize`

### Part D — Optional Entra access scoping

- [ ] **D1** Entra → **Enterprise applications** → **Pax8** → **Users and groups**
- [ ] **D2** Assign a security group (e.g. `Pax8 Technicians`) instead of whole tenant

### Part E — Test

- [ ] **E1** Private window → `https://app.onit.ltd` → Pax8 tile
- [ ] **E2** Lands on `app.pax8.com/login?login_hint=…` → identifier → **Continue** → Microsoft
- [ ] **E3** Global Admin accepts consent (first time only) → Pax8 dashboard

---

## Part 1 — Pax8 app users (do this first)

### Step A1 — Match Microsoft UPN

For each technician:

1. Entra → **Users** → open user → note **User principal name** (e.g. `tom.ashby@onit.ltd`)
2. Pax8 → **Users** → same user → username must match **exactly**
3. Portal `users.email` should match the same address

If UPN differs from mailbox alias, create a Pax8 app user with the **UPN**, not the alias.

### Step A2 — Create missing app users

1. Pax8 → **Users → Create Users**
2. Create app users with correct usernames
3. After SSO works, remove any duplicate pre-SSO users (Pax8 doc workflow)

---

## Part 2 — Enterprise SSO in Pax8

### Step B1 — Open Enterprise SSO

1. Sign in to Pax8 as **Primary Partner Admin**
2. **Admin → My Partner Profile**
3. **Enterprise SSO → Azure AD**

> **Partner Admin** cannot see this tab. **Primary Partner Admin** required.

### Step B2 — Domain configuration

| Field | On IT value | Notes |
|---|---|---|
| **Email Domain** | `onit.ltd` | Primary SMTP domain in Entra |
| **Domain Aliases** | *(blank or extra domains)* | Other UPN suffixes staff use |

Use Entra **Global Admin** link in Pax8 UI to confirm primary domain if unsure.

### Step B3 — DNS verification

1. Click **Create**
2. Copy **TXT verification** value(s) from Pax8
3. Add TXT record(s) at DNS host for `onit.ltd` (and aliases if any)
4. Wait a few minutes (up to hours for slow DNS)
5. Click **Verify Domain** — must show **Verified**
6. Check propagation: `nslookup -type=txt onit.ltd`

### Step B4 — Finalize

1. Click **Finalize**
2. Federation is live for all configured domains
3. Users with those email domains are redirected to Microsoft on login

---

## Part 3 — Portal configuration

Production `.env` on `app.onit.ltd`:

```env
PAX8_SSO_ENABLED=true
PAX8_PARTNER_PORTAL_URL=https://app.pax8.com
PAX8_PARTNER_LOGIN_PATH=/login
PAX8_COMPANY_URL_TEMPLATE=https://app.pax8.com/companies/{companyId}
PAX8_LOGIN_HINT_ENABLED=true
```

**Must use `https://app.pax8.com`.** Pax8 docs state `*.mycommandconsole.com` does **not** support Enterprise SSO.

After deploy:

```bash
php artisan config:clear
php artisan optimize
```

### How portal launch works

```
tom.ashby@onit.ltd → app.onit.ltd/dashboard → Pax8 tile
  → /integrations/pax8/launch
  → https://app.pax8.com/login?login_hint=tom.ashby%40onit.ltd
  → Auth0 → Microsoft (domain federated) → Pax8 partner console
```

---

## Part 4 — First login and Entra consent

1. Log out of Pax8
2. Open portal → **Pax8** (or go to `app.pax8.com/login`)
3. Email pre-filled via `login_hint` → click **Continue**
4. **First federated login:** Global Admin sees Microsoft **consent** for Pax8 → **Accept**
5. Subsequent technicians sign in with Microsoft only (MFA via Microsoft, not Pax8)

---

## Part 5 — Optional: restrict who can federate

By default, any Entra user in a federated domain who is also a Pax8 app user can sign in.

To narrow access:

1. [Azure Portal](https://portal.azure.com) → **Entra ID → Enterprise applications**
2. Find **Pax8** in the list
3. **Users and groups** → assign `Pax8 Technicians` (or similar) instead of whole tenant

Portal and Pax8 app user records are still required — Entra assignment is an extra gate.

---

## Part 6 — Per customer (company view)

Enterprise SSO does **not** apply to self-service customers yet (Pax8 FAQ).

For client users on the portal:

1. **Admin → Clients → Edit** → **Pax8 company ID**
2. Customer must exist as Pax8 user for that company
3. Portal launches `https://app.pax8.com/companies/{companyId}?login_hint=…`

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| No **Enterprise SSO** tab | Not Primary Partner Admin | Use Primary Partner Admin account |
| TXT verify fails | DNS not propagated or wrong record | Wait; `nslookup -type=txt onit.ltd` |
| Identifier page, no Microsoft after Continue | Domain not finalized or user not app user | Complete B7; check Pax8 user exists |
| Login fails after SSO enabled | UPN ≠ Pax8 username | Align Entra UPN, Pax8 user, portal email |
| SSO works on `app.pax8.com` but not custom URL | Expected — Pax8 limitation | Portal must launch `app.pax8.com` only |
| Password/MFA at Pax8 instead of Microsoft | Federation not active for domain | Re-check domains; use `app.pax8.com` |
| Customer cannot use Microsoft SSO | Not supported by Pax8 yet | Company deep link only |
| PSA iframe login broken after SSO | Known Pax8 limitation | Open Pax8 in separate tab |

---

## FAQs (from Pax8 Enterprise SSO guide)

| Question | Answer |
|---|---|
| Can some users bypass SSO? | No — all users in configured domains must use Microsoft |
| MFA after federation? | Microsoft only — no Pax8 MFA for new users |
| Customer self-service SSO? | Not at this time |
| Delete SSO connection? | All users logged out; revert to Pax8 credentials |
| Auto provision from Entra? | Pax8 working on API — not available yet |

---

## Quick reference — On IT values

| Item | Value |
|---|---|
| Pax8 partner | On IT LTD |
| Primary domain | `onit.ltd` |
| Launch URL | `https://app.pax8.com/login?login_hint={email}` |
| Portal route | `/integrations/pax8/launch` |
| Test technician | `tom.ashby@onit.ltd` (Primary Partner Admin) |
| Entra tenant ID | `586cc505-d298-4131-a3dc-9d1cd7c5ac0b` |
| Unsupported URL | `*.mycommandconsole.com` |

---

## Change log

| Date | Notes |
|---|---|
| 2026-06-22 | Initial guide from Pax8 Enterprise SSO v2 PDF; aligned with portal launch route |
