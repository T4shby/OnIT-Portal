# New customer tenant — technician setup guide

**For:** On IT technicians who have never used this portal before.  
**Time:** ~1–2 hours first time (less once you have done one customer).  
**You do not need to write code or use SSH** for most steps — the portal admin UI does the work.

---

## What is this app?

| URL | What it is |
|-----|------------|
| **https://app.onit.ltd** | **On IT Portal** — where customers sign in and open SuperOps, Pax8, etc. |
| **https://portal.onit.ltd** | **SuperOps requester portal** — tickets (hosted by SuperOps, not Laravel) |

**Your job:** connect a **new customer company** so their staff can:

1. Sign in to **app.onit.ltd** with their work Microsoft account  
2. Open **SuperOps** (support tickets) from the dashboard  
3. Optionally open **Pax8** (licensing view) if configured  
4. **Client admins** can browse **Microsoft 365** (users, shared mailboxes, groups) without the M365 admin centre  

**Already done for On IT staff (do not repeat per customer):**

- Technician login to SuperOps and Pax8 (`@onit.ltd` Enterprise SSO)  
- Portal hosting and On IT admin accounts  

This guide is **only for each new customer tenant**.

---

## Golden rules

1. **Work email must match everywhere** — same address in M365, SuperOps, and the portal.  
2. **Microsoft 365 is the source of truth** — disable or remove licences in M365; sync updates the portal and (via SCIM) SuperOps.  
3. **Use a private/incognito browser for testing** — do not test with `tom.ashby@onit.ltd` (that is a technician account).  
4. **Use the in-app wizard** — **Admin → Clients → Edit** → **Client setup** guide on the **right** (checklist, admin consent URL). **Dry run sync** / **Sync now** are on the **left** under Microsoft Entra sync.

---

## Who does what

| Person | Typical tasks |
|--------|----------------|
| **You (On IT technician)** | SuperOps client, portal client record, sync, test, handoff |
| **Customer M365 admin** (or you via GDAP) | Security group, admin consent, SCIM app, Client SSO SAML app |

If you have GDAP on the customer tenant, you can do the M365 steps yourself. Otherwise send them the consent URL and SCIM/SSO instructions from the sections below.

---

## Before you start — collect from the customer

| Item | Example |
|------|---------|
| Company name | Acme Ltd |
| Domain | `acme.com` |
| Who needs portal access? | List of work emails |
| Who is the **client admin**? (sees M365 directory) | `itmanager@acme.com` |
| SuperOps client exists? | Yes / No |
| Pax8 company exists? | Optional — for licensing tile |

---

## Part 1 — You: SuperOps (~10 min)

1. Sign in to the **SuperOps MSP console** (technician login — not the customer).  
2. Go to **Clients**.  
3. **Create** the client or open the existing one.  
4. Copy the **Account ID** (you will paste this into the portal).  
5. You do **not** need to manually add every requester long-term — **SCIM** (Part 3) will sync them. For the first setup, it helps if at least one test user exists as a requester with the correct email.

**Done when:** you have the SuperOps **Account ID** on a sticky note or notepad.

---

## Part 2 — You: Portal client record (~5 min)

1. Open **https://app.onit.ltd** and sign in with your **On IT** Microsoft account (`@onit.ltd`).  
2. Click **Admin** in the top navigation (only On IT staff see this).  
3. Go to **Clients → Add Client**.  
4. Fill in:

| Field | What to enter |
|-------|----------------|
| **Name** | Same as SuperOps client name (e.g. Acme Ltd) |
| **SuperOps Account ID** | From Part 1 |
| **SuperOps SSO enabled** | ✓ Tick |
| **Active** | ✓ Tick |
| **Pax8 Company ID** | Optional — only if they use the Pax8 licensing tile |
| **Pax8 access enabled** | ✓ if using Pax8 |

5. Click **Create**.  
6. Click **Edit** on the new client — the checklist on the right now tracks progress. Fields on the left are not saved until you click **Create** or **Update**.

**Note:** On Add Client, the guide on the right is a **preview** — step statuses (Pending / Blocked) only update after the client is saved and you are on **Edit**.

**Done when:** client exists and SuperOps Account ID is saved.

---

## Part 3 — M365 admin: security group + SCIM (~30 min)

These steps happen in the **customer's** Microsoft Entra tenant — **not** On IT's `@onit.ltd` tenant.

### 3a — Security group

1. Open [Azure Portal](https://portal.azure.com) → switch to the **customer** directory (top-right).  
2. **Microsoft Entra ID → Groups → New group**.  
3. Name: `On IT Portal - {Company}` (e.g. `On IT Portal - Acme Ltd`).  
4. Type: **Security**.  
5. Add members who should use **portal + SuperOps** (licensed users).  
   - Add **shared mailboxes** to this group only if they need to appear as SuperOps requesters for ticketing.  
6. Copy **Tenant ID** (Entra → Overview) and the group **Object ID** (open group → Overview).  
7. Send both IDs to yourself / paste into the portal in Part 5.

### 3b — Admin consent (Graph API)

The portal reads the customer tenant to **sync users** and show the **M365 directory**.

1. On **Admin → Clients → Edit**, find the **admin consent URL** in the setup panel (or build it):

```
https://login.microsoftonline.com/{CUSTOMER-TENANT-ID}/adminconsent?client_id={ON-IT-PORTAL-APP-CLIENT-ID}
```

2. Open that link while signed in as a **Global Admin** of the **customer** tenant.  
3. Click **Accept**.

**Required Application permissions** (already on the On IT Portal app registration — consent grants them in this tenant):

| Permission | Purpose |
|------------|---------|
| `User.Read.All` | List users |
| `LicenseAssignment.Read.All` | Find licensed users |
| `MailboxSettings.Read` | Detect shared mailboxes |
| `Group.Read.All` | M365 directory — groups and distribution lists |

### 3c — SuperOps SCIM (keeps SuperOps requesters in sync)

1. SuperOps → **Integrations → Microsoft Entra ID → Generate Tokens** → select **this client**.  
2. Copy **Tenant URL** and **Auth Token**.  
3. Customer Entra → **Enterprise applications → New application** → create a **non-gallery** app (e.g. `SuperOps Provisioning - Acme`).  
4. **Provisioning → Mode: Automatic**.  
5. Paste Tenant URL + Secret Token → **Test connection** → **Save**.  
6. **Users and groups** → assign `On IT Portal - {Company}`.  
7. Start provisioning (or wait for the sync cycle).

Detail: [SuperOpsEntraSync.md](SuperOpsEntraSync.md)

### 3d — SuperOps Client SSO (customer Microsoft login to SuperOps)

Separate app from SCIM — needed so the **SuperOps** tile works for `@customer.com` users.

1. SuperOps → **Settings → Requester Login → SSO Protected → Client SSO → + Configuration**.  
2. Copy **Entity ID** and **Consumer Service URL**.  
3. Customer Entra → **new non-gallery enterprise app** (SAML, not SCIM):  
   - Identifier + Reply URL from SuperOps  
   - Claims (lowercase): `email`, `firstname`, `lastname`  
   - Assign group `On IT Portal - {Company}`  
4. Copy Entra **Login URL** + certificate into SuperOps Client SSO config → **Save**.

Detail: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) (Client SSO section)

**Done when:** SCIM provisioning runs without errors; Client SSO saved in SuperOps.

---

## Part 4 — You: Portal Entra sync (~10 min)

Back on **https://app.onit.ltd → Admin → Clients → Edit** for this customer.

### Fields to set

| Field | Value |
|-------|--------|
| **Entra tenant ID** | Customer tenant GUID from Part 3a |
| **Entra group ID** | Optional — for your records / SCIM; portal sync does **not** require it |
| **Entra sync enabled** | ✓ Tick |

Click **Update** (main form) to save.

### What sync does

Reads the **whole customer tenant** and creates/updates portal users for:

- Every **licensed** M365 user → `Jane Smith (User)` — can sign in to portal  
- Every **shared mailbox** → `Accounts (Shared Mailbox)` — **cannot** sign in (for directory + SuperOps only)

Runs **hourly** automatically. You can run it manually now:

1. **Dry run sync** — shows what would change (no database writes).  
2. **Sync now** — applies changes.

Or via SSH (replace `{id}` with client ID from the URL):

```bash
php artisan portal:sync-entra-users --client={id} --dry-run
php artisan portal:sync-entra-users --client={id}
```

3. Go to **Admin → Users** — filter mentally by client; confirm expected people exist with correct emails.

Detail: [EntraGroupSync.md](EntraGroupSync.md) · [AccessAndSync.md](AccessAndSync.md)

**Done when:** licensed test users appear under **Admin → Users**.

---

## Part 5 — You: Client admin + M365 directory (~5 min)

**Client admins** see **Microsoft 365** in the portal nav (users, shared mailboxes, groups, distribution lists).

1. **Admin → Users → Add User** (or confirm sync created them).  
2. Set:

| Field | Value |
|-------|--------|
| **Email** | Customer admin work email (must be licensed + synced) |
| **Role** | **Client admin** |
| **Client** | This customer |
| **Active** | ✓ |

3. Ask them to sign in and open **Microsoft 365** in the top menu.

**Done when:** client admin sees People and Groups tabs with live data.

---

## Part 6 — Test (~15 min)

Use a **private/incognito** window. Sign in as a **customer** user (e.g. `jane@acme.com`) — **not** `@onit.ltd`.

| # | Test | Pass? |
|---|------|-------|
| 1 | https://app.onit.ltd/login → Microsoft → dashboard loads with correct organisation | ☐ |
| 2 | **SuperOps** tile → requester view (not technician role chooser) | ☐ |
| 3 | https://portal.onit.ltd/#/requester/login → Microsoft → requester home | ☐ |
| 4 | **Pax8** tile (if enabled) → company licensing view | ☐ |
| 5 | **Client admin:** **Microsoft 365** menu → licensed users + shared mailboxes + groups | ☐ |

### Common errors

| Symptom | Fix |
|---------|-----|
| "Your account has not been set up" | Run **Sync now** (Part 4); user must be licensed or shared mailbox in M365 |
| SuperOps role chooser | Wrong account (`@onit.ltd`) or Client SSO not finished (Part 3d) |
| SuperOps Error 1027 | Missing `email` SAML claim in Client SSO app |
| M365 directory empty / error | Admin consent not done in customer tenant (Part 3b) |
| Shared mailbox tried to log in | Expected — they cannot use portal login; use a personal work account |

---

## Part 7 — Hand off to the customer

Send something like:

> **On IT Portal:** https://app.onit.ltd  
> Sign in with your **work Microsoft account** (same as email/Teams).  
> From the dashboard you can open **SuperOps** for support and (if enabled) **Pax8** for subscriptions.  
> **IT admins:** use the **Microsoft 365** menu to view users, shared mailboxes, and groups without the M365 admin centre.

---

## After go-live — day to day

| Event | What happens |
|-------|----------------|
| **New licensed user in M365** | Appears in portal on next sync (up to 1 hour); add to SCIM group for SuperOps |
| **New shared mailbox** | Synced to portal + directory; add to SCIM group only if needed for SuperOps tickets |
| **User leaves** | Disable in M365 or remove licence → portal deactivates on sync; remove from SCIM group |
| **Force refresh M365 directory** | Client admin → **Microsoft 365** → **Refresh now** |

You can confirm portal state anytime: **Admin → Users** (filter by client).

---

## Server deploy — commands after GitHub pull

Run these on the **server** after Plesk pulls new code from GitHub (or after any deploy). SSH as root; adjust the path if yours differs.

```bash
cd /var/www/vhosts/onit.ltd/app.onit.ltd

# Plesk: php is not on default PATH
export PATH="/opt/plesk/php/8.3/bin:$PATH"
export COMPOSER_ALLOW_SUPERUSER=1
php -v

rm -f public/hot

composer install --no-dev --optimize-autoloader

php artisan migrate --force

php artisan route:clear
php artisan config:clear
php artisan view:clear

php artisan db:seed --class=PortalLinkSeeder --force

php artisan optimize
```

**Also check `.env` on the server:**

```env
ENTRA_SYNC_ENABLED=true
```

**Cron** (Plesk → Scheduled Tasks) — required for hourly user sync:

```
* * * * * cd /var/www/vhosts/onit.ltd/app.onit.ltd && /opt/plesk/php/8.3/bin/php artisan schedule:run >> /dev/null 2>&1
```

Full hosting detail: [Deployment.md](Deployment.md)

---

## Quick reference — three Entra apps per customer

| App in customer tenant | Purpose |
|------------------------|---------|
| On IT Portal (consent only) | Portal login + Graph sync + M365 directory |
| SuperOps SCIM provisioning | Auto-create/update SuperOps requesters |
| SuperOps Client SSO (SAML) | Microsoft login to SuperOps requester portal |

---

## More detail (if stuck)

| Topic | Document |
|-------|----------|
| In-app wizard | [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) |
| SCIM | [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| Portal sync | [EntraGroupSync.md](EntraGroupSync.md) |
| Sync model | [AccessAndSync.md](AccessAndSync.md) |
| Pax8 customer tile | [Pax8CustomerAccess.md](Pax8CustomerAccess.md) |
| Production deploy | [Deployment.md](Deployment.md) |

---

## Change log

| Date | Change |
|------|--------|
| 2026-06-23 | Initial guide — tenant-wide sync, M365 directory, client admin, post-deploy commands |
