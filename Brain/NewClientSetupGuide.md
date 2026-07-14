# New Client Setup Guide (start to finish)

> **Primary workflow:** **Admin → Clients → Add Client → Edit** — use the in-app **Client setup** wizard (10-step checklist on Edit, install-manual format, consent URL on step 04, sync on left). This document is the long-form backup.

Follow this document top to bottom when onboarding a **real customer** onto the On IT Portal. Tick each box as you go.

**Who this is for:** On IT staff (you or a colleague). No Laravel or coding required for the steps below.

**Portal URL:** https://app.onit.ltd  
**SuperOps requester portal:** https://portal.onit.ltd (SuperOps only, not Laravel)

**Before you start:** read [ClientOnboarding.md](ClientOnboarding.md) once if you need background. This guide is the practical walkthrough.

---

## What you need from the customer

Collect this before you touch any system:

| Item | Example |
|---|---|
| Company name | Acme Ltd |
| Primary contact name | Jane Smith |
| Work email(s) for portal access | `jane@acme.com` |
| Do they use **their own** Microsoft 365? | Yes (normal) / No (rare) |
| SuperOps already has this client? | Yes / No |

**Golden rule:** every user's **work email** must match exactly in SuperOps, the portal, and (if applicable) Entra.

---

## Choose the path

| | Path A (rare) | Path B (most customers — **this is the normal path**) |
|---|---|---|
| User emails look like | `@onit.ltd` or guest in On IT tenant | `@customerdomain.com` |
| Portal sign-in | Microsoft (On IT tenant) | Microsoft (customer tenant) |
| SuperOps SSO | Global SSO (On IT home) | **Same Global SSO** + customer GA **Accept** (checklist **06**) — **not** Client SSO |

If the customer has their own Microsoft 365, you are on **Path B**. Follow every step in this guide. Step **06** Accept is mandatory for every customer tenant.

---

## Step 1: SuperOps - create or confirm the client

1. Sign in to the **SuperOps MSP console** (technician login).
2. Go to **Clients**.
3. **Create** the client if they do not exist, or open the existing record.
4. Note the **Account ID** (you need this for the portal).
5. Go to **Clients → {Customer} → Users**.
6. For each person who needs access, click **Add requester** and use their **work email**.

| Done | Task |
|---|---|
| ☐ | Client exists in SuperOps |
| ☐ | Account ID copied |
| ☐ | All requester users added (same emails they will use for Microsoft) |

---

## Step 2: Portal - create the client

1. Sign in to https://app.onit.ltd as an On IT admin (`super_admin` or `account_manager`).
2. Go to **Admin → Clients → Add Client**.
3. Fill in:

| Field | What to enter |
|---|---|
| Name | Customer display name (e.g. Acme Ltd) |
| SuperOps Account ID | From Step 1 |
| SuperOps SSO enabled | ✓ (tick) |
| Active | ✓ (tick) |

4. Click **Create**. The slug is generated automatically from the name.

| Done | Task |
|---|---|
| ☐ | Client created in portal |
| ☐ | SuperOps Account ID saved |
| ☐ | SuperOps SSO enabled is on |

---

## Step 3: Portal - create each user

Repeat for every person who needs portal access.

1. Go to **Admin → Users → Add User**.
2. Fill in:

| Field | What to enter |
|---|---|
| Name | Full name |
| Email | Their **work Microsoft email** (must match SuperOps requester) |
| Role | `Client user` (normal) or `Client admin` (manages their org if needed later) |
| Client | Select the customer from Step 2 |
| Active | ✓ |

3. Click **Create**.

| Done | Task |
|---|---|
| ☐ | All users created |
| ☐ | Each user linked to the correct client |
| ☐ | Emails match SuperOps requesters exactly |

There is **no automatic portal sync** until Entra sync is configured on the client (`entra_tenant_id`, `entra_group_id`, `entra_sync_enabled`). When enabled, users are created from the M365 security group — see [EntraGroupSync.md](EntraGroupSync.md). SuperOps requesters use SCIM — see [SuperOpsEntraSync.md](SuperOpsEntraSync.md).

---

## Step 4: SuperOps Global SSO Accept (every customer tenant)

Do **not** open SuperOps **Client SSO**. Platform Global SSO is already configured once. Every customer still needs **Accept**.

### 4a. Portal checklist step 06

1. **Admin → Clients → Edit** this client (Entra tenant ID already saved from step 03).
2. Open step **06 — SuperOps requester SSO (Global SSO Accept)**.
3. Click **Open** on **SuperOps SSO Accept URL** (or send Copy to customer GA).
4. Sign in as a **customer** Global Admin → **Accept**.

### 4b. Customer Entra — assign who can sign in

1. In the **customer** tenant → Enterprise applications → **SuperOps Requester SSO (On IT)** (appears after Accept).
2. Users and groups → assign security group **`On IT Portal - {Company}`**.
3. Do not change Reply URL / certificate / Global SSO settings for this client.

Full detail: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).

| Done | Task |
|---|---|
| ☐ | Customer GA Accepted SuperOps Requester SSO |
| ☐ | Portal group assigned in customer tenant |
| ☐ | Incognito Microsoft login with customer work email works |
| ☐ | Customer users assigned to SAML app |
| ☐ | Test login at https://portal.onit.ltd/#/requester/login works for one user |

---

## Step 5: Path A only (On IT tenant users)

If users are in **On IT's** Microsoft tenant (not the customer's):

1. Entra → **Groups** → create `Client - {Name} - Portal`.
2. Add each user to the group (or let Sync now maintain membership).
3. After checklist **06** Accept: **customer** Enterprise applications → SuperOps Requester SSO (On IT) → Users and groups → assign the Portal group.

| Done | Task |
|---|---|
| ☐ | Entra group created |
| ☐ | Group assigned to SuperOps Requester SSO in customer tenant (after Accept) |

---

## Step 6: Test before handoff

Use a **private/incognito** browser. Use a **client test account**, not `tom.ashby@onit.ltd` (Tom is a SuperOps technician and gives misleading results).

### Portal tests

| Done | Test |
|---|---|
| ☐ | Open https://app.onit.ltd/login |
| ☐ | Sign in with Microsoft using the client's work email |
| ☐ | Dashboard shows the user's name and organisation |
| ☐ | **SuperOps** logo/link opens SuperOps as a **requester** (not technician, no role chooser) |
| ☐ | **Pax8** logo/link opens Pax8 |

### SuperOps direct test (Path B)

| Done | Test |
|---|---|
| ☐ | Open https://portal.onit.ltd/#/requester/login in incognito |
| ☐ | Microsoft login → lands on requester dashboard (`/#/client-home`) |

If SuperOps shows **Error 1027**, the `email` claim is missing in Entra. See [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).

---

## Step 7: Hand off to the customer

Send the customer:

1. Portal URL: **https://app.onit.ltd**
2. Instruction: **Sign in with Microsoft** using their work account.
3. What they will see: SuperOps and Pax8 links on the dashboard.
4. Support contact if login fails.

**Do not** share internal admin URLs or technician SuperOps login.

---

## Adding another user later

When someone new joins an existing client:

| # | System | Action |
|---|---|---|
| 1 | Portal | **Admin → Users → Add User** (same client, work email) |
| 2 | SuperOps | Client → **Users → Add requester** (same email) |
| 3 | Entra (Path A) | Add to client's portal group |
| 4 | Entra (Path B) | Customer IT adds to their SAML app / group |
| 5 | Test | They sign in at https://app.onit.ltd |

---

## Worksheet (print or copy)

```
Customer name:           _______________________________
Path:                    [ ] A (On IT M365)  [ ] B (own M365)

SuperOps account ID:     _______________________________
Portal client created:   [ ] Yes   Date: __________
SuperOps SSO enabled:    [ ] Yes

Users:
  Name                 Email                      Portal  SuperOps  Tested
  ________________     ____________________       [ ]     [ ]       [ ]
  ________________     ____________________       [ ]     [ ]       [ ]

Client SSO done (B):    [ ] Yes   Date: __________
Handed off to client:   [ ] Yes   Date: __________
Completed by:          _______________________________
```

---

## If something breaks

| Problem | Check |
|---|---|
| Cannot sign in to portal | User exists in **Admin → Users** with correct email and Active ticked |
| SuperOps opens role chooser | Launch path must be `/#/requester/login` in server `.env` |
| Error 1027 after Microsoft | Entra SAML `email` claim missing or wrong namespace |
| SuperOps opens as technician | You tested with an MSP staff account; use a client requester |
| SuperOps card missing | Client has **SuperOps SSO enabled**; user role is `client_user` or `client_admin` |
| Pax8 missing on dashboard | Set Pax8 vars in `.env` ([Pax8Integration.md](Pax8Integration.md)); run `php artisan db:seed --class=PortalLinkSeeder --force`; set `pax8_company_id` on client for customer users |

Escalate to Tom with: exact URL, screenshot, email used, and time of failure.

---

## Related docs

| Doc | Use when |
|---|---|
| [ClientOnboarding.md](ClientOnboarding.md) | Full reference, all paths, platform values |
| [OperatorRunbook.md](OperatorRunbook.md) | SSO testing, production ops |
| [Deployment.md](Deployment.md) | Plesk deploy and `.env` |
| [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) | Entra SAML deep dive |
