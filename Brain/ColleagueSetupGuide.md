# Colleague Setup Guide (simple version)

> **Status:** Use the **in-app wizard**: Admin → Clients → Edit (12 steps). Entry point: [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md).

Give this document to someone who will **create the client record** but does not run Entra Accept / SCIM.

**They can do:** create the client, paste SuperOps Account ID, optional Pax8.
**A trained technician must do:** Entra Accept steps, SCIM, sync, and customer handoff.

**Portal:** https://app.onit.ltd  
**Sign in:** Use your On IT work account. You need **Admin** access in the portal.

---

## What you are doing (in plain English)

For each customer company we support:

1. Tell **SuperOps** the company exists and which people can use support.
2. Tell the **On IT Portal** the same company exists and which people can sign in.
3. **Tom** connects Microsoft login for that company (you do not do this step).
4. **Test** with one user before we email the customer.

Every person needs the **same work email** in SuperOps and the portal.

---

## Before you start (one row per company)

Use a spreadsheet. Tom can give you a template.

| Company name | SuperOps account ID | Portal done? | Microsoft SSO done? (Tom) | Tested? | Notes |
|---|---|---|---|---|---|
| Acme Ltd | | | | | |

For users, a second sheet:

| Company | Full name | Work email | Added in SuperOps? | Added in portal? |
|---|---|---|---|---|
| Acme Ltd | Jane Smith | jane@acme.com | | |

---

## Part A: One new company (repeat for all 60)

### A1. SuperOps (about 5 minutes)

1. Open SuperOps and sign in as **technician** (normal MSP login, not the customer portal).
2. Go to **Clients**.
3. If the company is not there: **Add client** with the correct name.
4. Open the client and copy the **Account ID** into your spreadsheet.
5. Stop here for now. You will add users in Part B.

**Tick:** ☐ Company exists in SuperOps ☐ Account ID saved

### A2. On IT Portal (about 3 minutes)

1. Go to https://app.onit.ltd and sign in.
2. Click **Admin** in the top menu.
3. Click **Clients** → **Add Client**.
4. Fill in:
   - **Name:** company name (same as SuperOps)
   - **SuperOps Account ID:** paste from spreadsheet
   - **SuperOps SSO enabled:** tick the box
   - **Active:** tick the box
5. Click **Create**.

**Tick:** ☐ Company exists in portal

### A3. Ask Tom to do Microsoft login (you wait)

Send Tom a message with:

- Company name  
- SuperOps Account ID  
- Confirm they use their **own** Microsoft 365 (almost always yes)

Tom will complete checklist **06** (customer Global Admin **Accepts** SuperOps Requester SSO) so users can Microsoft-sign-in with `@theircompany.com`. You cannot skip this Accept.

**Tick:** ☐ Tom confirmed SuperOps SSO Accept is done

---

## Part B: Add people (repeat for each user)

### B1. SuperOps

1. SuperOps → **Clients** → pick the company → **Users**.
2. **Add requester**.
3. Email = their **work email** (e.g. `jane@acme.com`).
4. Save.

### B2. On IT Portal

1. https://app.onit.ltd → **Admin** → **Users** → **Add User**.
2. Fill in:
   - **Name:** full name
   - **Email:** same work email as SuperOps
   - **Role:** **Client user** (normal staff) or **Client admin** (their IT lead)
   - **Client:** pick the company
   - **Active:** tick the box
3. Click **Create**.

**Tick:** ☐ User in SuperOps ☐ User in portal ☐ Emails match exactly

---

## Part C: Quick test (use a test user, not Tom's account)

1. Open a **private/incognito** browser window.
2. Go to https://app.onit.ltd/login.
3. Sign in with the **test user's work Microsoft account**.
4. Check:
   - ☐ You see their name and company on the dashboard
   - ☐ **SuperOps** link opens SuperOps (not a "pick technician or requester" screen)
   - ☐ **Pax8** link opens Pax8

If anything fails, note the exact error and send to Tom. Do not email the customer until the test passes.

---

## Part D: Tell the customer

Send only this:

> You can access your On IT portals at **https://app.onit.ltd**  
> Click **Sign in with Microsoft** and use your work email.  
> If you have trouble, contact On IT support.

Do not send admin links or technician SuperOps login.

---

## When someone leaves the company

**Today this is not automatic.** Someone must do these steps:

| Who | Action |
|---|---|
| **Tom** | Disable or remove the user in **Microsoft 365** (their tenant) |
| **Tom** | Remove user from the customer's **SuperOps SSO app** in Entra (if applicable) |
| **You** | Portal → **Admin → Users** → find user → **Edit** → untick **Active** (or delete) |
| **You** | SuperOps → client → **Users** → remove or disable the requester |

If you only disable Microsoft but leave the portal user **Active**, they may still have a portal row (but Microsoft sign-in usually blocks disabled accounts). Always untick **Active** in the portal and remove them in SuperOps for a clean offboard.

---

## Common mistakes

| Mistake | Fix |
|---|---|
| Email typo in portal vs SuperOps | Edit both to match exactly |
| User "not set up" at login | Add them in **Admin → Users** first |
| SuperOps shows role chooser | Tell Tom; wrong login path or SSO config |
| Tested with Tom's email | Use a real client user; Tom opens as technician |
| Skipped Tom's Microsoft step | Users cannot sign in with their work account |

---

## How much work for 60 companies?

| Task | Who | How often |
|---|---|---|
| SuperOps client + portal client | You | Once per company |
| Microsoft SSO | Tom | Once per company |
| Each new employee | You (+ Tom for M365 if needed) | Per person |
| Each leaver | You + Tom | Per person |

**Automatic sync from Microsoft is live** when `ENTRA_SYNC_ENABLED=true` and the client has Entra sync configured. See [AccessAndSync.md](AccessAndSync.md) and [EntraGroupSync.md](EntraGroupSync.md).

---

## Need more detail?

| Document | When |
|---|---|
| [NewClientSetupGuide.md](NewClientSetupGuide.md) | Full technical walkthrough |
| [AccessAndSync.md](AccessAndSync.md) | Auto-sync, offboarding, what is built today |
| [ClientOnboarding.md](ClientOnboarding.md) | Reference for Tom |

**Questions:** escalate to Tom with company name, user email, and screenshot.
