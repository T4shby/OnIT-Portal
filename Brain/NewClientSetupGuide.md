# New Client Setup Guide (start to finish)

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

| | Path A | Path B (most customers) |
|---|---|---|
| User emails look like | `@onit.ltd` or guest in On IT tenant | `@customerdomain.com` |
| Portal sign-in | Microsoft (On IT tenant) | Microsoft (customer tenant) |
| SuperOps SSO | On IT **Global SSO** (already set up) | **Client SSO** per customer (you set up once per client) |

If the customer has their own Microsoft 365, you are on **Path B**. Follow every step in this guide.

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

## Step 4: SuperOps Client SSO (Path B only)

Skip this section if the customer uses Path A (On IT tenant). For normal customers with their own M365, do this **once per client**.

### 4a. SuperOps

1. **Settings → Requester Login → SSO Protected → Client SSO**.
2. Click **+ Configuration** for this client.
3. Copy the **Entity ID** and **Consumer Service URL** (client-specific values).
4. Keep this page open.

### 4b. Customer's Microsoft Entra (client IT may need to help)

1. Customer's Entra admin creates a **non-gallery enterprise application** (SAML).
2. Paste SuperOps **Entity ID** and **Reply URL** from 4a.
3. Add SAML claims (names must be **lowercase**):

| Claim | Source |
|---|---|
| `email` | `user.mail` or `user.userprincipalname` |
| `firstname` | `user.givenname` |
| `lastname` | `user.surname` |

4. Download the SAML certificate (Base64 body only in SuperOps, no BEGIN/END lines).
5. Copy the Entra **Login URL**.
6. Assign the customer's users or a security group to this app.

### 4c. Back in SuperOps

1. Paste certificate and Login URL into the Client SSO configuration.
2. Link the configuration to the client.
3. Save.

Full detail: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) and [SuperOps Client SSO article](https://support.superops.com/en/articles/11583025-setting-up-requester-sso-in-superops).

| Done | Task |
|---|---|
| ☐ | Client SSO configured in SuperOps |
| ☐ | SAML app created in customer's Entra |
| ☐ | Customer users assigned to SAML app |
| ☐ | Test login at https://portal.onit.ltd/#/requester/login works for one user |

---

## Step 5: Path A only (On IT tenant users)

If users are in **On IT's** Microsoft tenant (not the customer's):

1. Entra → **Groups** → create `Client - {Name} - Portal`.
2. Add each user to the group.
3. **Enterprise applications → SuperOps Requester SSO (On IT) → Users and groups** → assign the group.

Do **not** add customer `@theircompany.com` users to On IT's Global SSO app. That is Path B (Step 4).

| Done | Task |
|---|---|
| ☐ | Entra group created and assigned to SuperOps Requester SSO app |
| ☐ | All users in the group |

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
| Pax8 missing | `PAX8_URL` set in production `.env`; run portal link seeder if needed |

Escalate to Tom with: exact URL, screenshot, email used, and time of failure.

---

## Related docs

| Doc | Use when |
|---|---|
| [ClientOnboarding.md](ClientOnboarding.md) | Full reference, all paths, platform values |
| [OperatorRunbook.md](OperatorRunbook.md) | SSO testing, production ops |
| [Deployment.md](Deployment.md) | Plesk deploy and `.env` |
| [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) | Entra SAML deep dive |
