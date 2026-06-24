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
4. **Use the in-app wizard** — **Admin → Clients → Edit** → **Client setup** guide on the **right** (install-manual format: **Before you start**, numbered parts, **Check your work**; admin consent URL on step 05). **Dry run sync** / **Sync now** are on the **left** under Microsoft Entra sync.

---

## Who does what

| Person | Typical tasks |
|--------|----------------|
| **On IT technician (portal / SuperOps)** | SuperOps client, portal client record, Pax8 ID, enable sync, dry-run, test, handoff |
| **On IT technician (customer Entra / GDAP)** | Security group, SCIM app, admin consent, Client SSO SAML — all in the **customer** tenant |

If GDAP is not available, the customer Global Admin runs admin consent; On IT still owns the checklist until complete.

This is the **same process for every MSP customer** — scale by repeating **Admin → Clients → Edit** per company.

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

5. Click **Create** once — you land on **Edit Client** with an **Update** button (not Create again). The setup checklist appears on the right.

**Done when:** client exists and SuperOps Account ID is saved (checklist step 2).

---

## Part 3 — M365: group + consent + SuperOps app (~30 min)

**Full click-by-click:** [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md)

These steps happen in the **customer's** Microsoft Entra tenant — **not** On IT's `@onit.ltd` tenant.

**Prerequisite (On IT tenant, once per platform):** Graph Application permissions on **OnIT Portal for Portals** including `GroupMember.ReadWrite.All` — [runbook Step 0](CustomerEntraSyncRunbook.md#step-0--graph-permissions-on-it-tenant-one-time).

### 3a — Empty security group

1. Azure Portal → switch to **customer** directory (top-right).  
2. **Microsoft Entra ID → Groups → New group**.  
3. Name: `On IT Portal - {Company}`. Type: **Security**. Membership: **Assigned**.  
4. **Do not add members** — portal sync fills the group automatically.  
5. Copy group **Object ID** → portal **Entra group ID**.  
6. Copy **Tenant ID** from Entra Overview → portal **Entra tenant ID**.

### 3b — Admin consent (customer tenant)

1. **Admin → Clients → Edit** → checklist step **05** — open the consent URL.  
2. Sign in as **customer** Global Admin (or GDAP).  
3. Click **Accept**.

Must include `GroupMember.ReadWrite.All` if group auto-maintain is enabled. Re-consent if permissions were added after an earlier consent.

If consent fails with `GroupMember.ReadWrite.All does not exist in RequiredResourceAccess` on the **On IT** app: refresh the Azure page and try again — see [runbook](CustomerEntraSyncRunbook.md#04-if-consent-fails-with-groupmemberreadwriteall-does-not-exist-in-requiredresourceaccess).

**Application permissions consented in customer tenant:**

| Permission | Purpose |
|------------|---------|
| `User.Read.All` | List users |
| `LicenseAssignment.Read.All` | Licensed users |
| `MailboxSettings.Read` | Shared mailboxes |
| `Group.Read.All` | M365 directory |
| `GroupMember.ReadWrite.All` | Auto-fill SuperOps SCIM group |

### 3c — One SuperOps Entra app: SCIM + SAML

**Default:** one non-gallery app `SuperOps - {Company}` — **not** two separate apps.

**SCIM (checklist step 06):**

1. SuperOps → **Integrations → Microsoft Entra ID → Generate Tokens** → this client.  
2. Customer Entra → **Enterprise applications → New application** → `SuperOps - {Company}`.  
3. **Provisioning → Automatic** → Tenant URL + Secret Token → **Test connection** → Save.  
4. **Users and groups** → assign `On IT Portal - {Company}` (empty group).  
5. Start provisioning.

**SAML (checklist step 07) — same app, do not create a second:**

1. SuperOps → **Client SSO** → copy Entity ID + Reply URL.  
2. On **`SuperOps - {Company}`** → **Single sign-on → SAML** → configure + claims + certificate.  
3. Paste Login URL + cert back into SuperOps.

Detail: [SuperOpsEntraSync.md](SuperOpsEntraSync.md) · [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md)

**Done when:** SCIM logs clean; Client SSO saved; group assigned once to the single app.

---

## Part 4 — You: Portal Entra sync (~10 min)

Back on **https://app.onit.ltd → Admin → Clients → Edit** for this customer.

### Fields to set

| Field | Value |
|-------|--------|
| **Entra tenant ID** | Customer tenant GUID from Part 3a |
| **Entra group ID** | Group Object ID — **required** for auto SuperOps group membership |
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
4. Sync output should include **`SuperOps group: +N / -M members`** when **Entra group ID** is set.
5. In customer Entra → **Groups → On IT Portal - {Company} → Members** — users appear without manual adds.

Detail: [EntraGroupSync.md](EntraGroupSync.md) · [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md)

**Done when:** licensed users in portal; group members populated; SCIM logs show provisioning.

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
