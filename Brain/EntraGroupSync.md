# Entra tenant sync - Sync 2 (portal only)

Sync **portal users** from a customer's Microsoft Entra tenant. Part of the **two-sync** model - see [AccessAndSync.md](AccessAndSync.md).

| Sync | What | Doc |
|---|---|---|
| **1** | M365 → SuperOps requesters (SCIM) | [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| **2** | M365 → Portal users (this doc) | `portal:sync-entra-users` |

**Scope:** All **licensed** M365 users and **shared mailboxes** in the customer tenant. When `entra_group_id` is set, the same scope is **written into** the SuperOps SCIM security group automatically.

---

## Which Entra app? (read this first)

You already have **two** Entra apps. Sync does **not** need a third unless you choose to create one.

| App | Purpose | Protocol | `.env` keys |
|---|---|---|---|
| **On IT Portal** (app #1) | Users sign in to `app.onit.ltd` | OAuth / OIDC | `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET` |
| **SuperOps requester SSO** (app #2) | Users open SuperOps tickets | SAML | Separate - see [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) |
| **Graph sync** | Portal reads group members | Uses app #1 credentials | Same as OAuth by default |

### Recommended: extend the existing Portal OAuth app

**Do not create a new app** unless you want sync isolated from login (unusual).

1. Open the **same** app registration you use for portal login (`MICROSOFT_CLIENT_ID`).
2. Add **Application** (not Delegated) Graph permissions - see [Step 1 below](#step-1-add-graph-permissions-to-the-portal-oauth-app-on-it-tenant).
3. Grant **admin consent in each customer tenant** where you sync - see [Step 2](#step-2-admin-consent-in-each-customer-tenant).

The portal code uses `MICROSOFT_CLIENT_ID` / `MICROSOFT_CLIENT_SECRET` for Graph unless you set optional `ENTRA_SYNC_CLIENT_ID` / `ENTRA_SYNC_CLIENT_SECRET` (dedicated sync app - only if you create one).

**Login is unchanged.** Existing Delegated permissions (`openid`, `profile`, `email`, `User.Read`) stay as they are. Sync adds Application permissions on top.

---

## What sync does

| Action | Behaviour |
|---|---|
| **Licensed** M365 user in tenant | Create or **update by Entra object id** (else bind by email). Updates `email` when primary changes. Role only on create - never downgrades `client_admin` / billing. Plain M365 name. |
| **Shared mailbox** in tenant | Same upsert; `portal_login_enabled=false` - `(Shared Mailbox)` suffix goes to `extensionAttribute1` for SuperOps only |
| User **primary email / domain** changes | **Update** `users.email` in place (object id constant). Retires same-client Entra-sync **duplicate** rows from older email-only matching. [DomainEmailChange.md](DomainEmailChange.md) |
| User **no longer licensed** (not a shared mailbox) | Deactivate portal user (`is_active=false`) if provisioned by sync - keyed by **object id** not email |
| User **disabled** in Entra | Portal user set inactive |
| **Manual** portal users | Not managed by sync (`provisioned_by = manual`) - skipped |
| **SuperOps SCIM group** | When `entra_group_id` is set, sync **adds/removes** licensed users + shared mailboxes in that security group via Graph (`GroupMember.ReadWrite.All`) |
| **SuperOps SCIM enterprise app (Entra ID Free)** | When `entra_superops_app_id` is set, sync **assigns/removes** licensed active users **and shared mailboxes** via Graph (`AppRoleAssignment.ReadWrite.All`) |
| **SuperOps Client SSO app (Entra ID Free)** | After checklist 08, when `entra_superops_sso_app_id` is set, sync assigns/removes **active licensed users** for requester login |
| **SuperOps requester display names** | Sync writes full last name to `extensionAttribute1` (e.g. `Smith (User Mailbox)`) - SCIM **Direct** on `name.familyName` |
| **SuperOps requesters (create/deprovision)** | SCIM from app assignment (direct users or group members) |
| **SuperOps requester primary email (domain change)** | After portal user upsert, **SuperOps API** `updateClientUser` aligns email to Graph primary; binds `users.superops_user_id` when matched - [DomainEmailChange.md](DomainEmailChange.md). Optional: `ENTRA_SYNC_SUPEROPS_EMAIL_ALIGN=false` to pause |

Create the security group **empty** in Entra. Paste its Object ID as `entra_group_id`. Each sync run keeps group membership aligned with licensed users + shared mailboxes so SCIM provisions the right requesters.

---

## Deploy to server (production)

Feature is on **`main`**. After pulling code:

```bash
cd /var/www/vhosts/onit.ltd/app.onit.ltd
export PATH="/opt/plesk/php/8.3/bin:$PATH"
export COMPOSER_ALLOW_SUPERUSER=1
rm -f public/hot
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan view:clear
php artisan optimize
```

Edit server `.env` (see [Step 4](#step-4-server-env) below). Set `ENTRA_SYNC_ENABLED=true`.

**Cron:** `schedule:run` every minute ([Deployment.md](Deployment.md)). Hourly `portal:sync-entra-users` **queues one `SyncEntraClientJob` per enabled client** (does not run Graph inline). Use `--inline` for a foreground sync when debugging. Mailbox purpose is only fetched for **unlicensed** users (shared mailboxes), in Graph `$batch` chunks of 20.

---

## Step 1: Add Graph permissions to the Portal OAuth app (On IT tenant)

**Full click-by-click instructions:** [CustomerEntraSyncRunbook.md § Step 0](CustomerEntraSyncRunbook.md#step-0--graph-permissions-on-it-tenant-one-time)

Do this in **On IT Technology Partners LTD** (not the customer tenant).

| Item | Production value |
|------|------------------|
| App registration name | **OnIT Portal for Portals** |
| Matches `.env` | `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET` |

### Summary

1. **Microsoft Entra ID → App registrations → OnIT Portal for Portals → API permissions**
2. **+ Add a permission → Microsoft Graph → Application permissions** (not Delegated)
3. Add all Application permissions (see full table in [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) Step 0), including Connect bootstrap + client security posture:
   - `User.Read.All`
   - `User.ReadWrite.All`
   - `LicenseAssignment.Read.All`
   - `MailboxSettings.Read`
   - `Group.Read.All`
   - `Group.ReadWrite.All` (create portal group on Connect)
   - `GroupMember.ReadWrite.All`
   - `AppRoleAssignment.ReadWrite.All`
   - `Application.Read.All`
   - `Application.ReadWrite.All` (create SuperOps SCIM + Client SSO apps)
   - `Synchronization.ReadWrite.All`
   - `SecurityEvents.Read.All` (Secure Score on client Dashboard)
   - `AuditLog.Read.All` (MFA registration report)
   - `Reports.Read.All` (MFA registration report)
4. **Add permissions** → **Grant admin consent for On IT Technology Partners LTD**

### Expected result

| Type | Permissions | Status |
|------|-------------|--------|
| Delegated (4) | email, openid, profile, User.Read | Granted |
| Application (14) | …eleven sync/bootstrap… + SecurityEvents.Read.All, AuditLog.Read.All, Reports.Read.All | Granted |

After any **new** Application permission: re-Accept in each customer tenant (Connect Microsoft) - [runbook §0.3a](CustomerEntraSyncRunbook.md#step-0--graph-permissions-on-it-tenant-one-time). Do not delete portal clients.

### Consent error fix

If **Grant admin consent** fails with:

> `GroupMember.ReadWrite.All does not exist in client application's RequiredResourceAccess`

1. **Refresh the browser page** (F5)
2. Confirm all five Application permissions are still listed
3. Click **Grant admin consent** again

No manifest edit or PowerShell required - refresh fixed this in production (June 2026).

---

## Step 2: Admin consent in each customer tenant

**Order:** Complete [Step 1](#step-1-add-graph-permissions-to-the-portal-oauth-app-on-it-tenant) in On IT tenant **before** customer consent, so `GroupMember.ReadWrite.All` is included.

**Full sequence:** [CustomerEntraSyncRunbook.md - Order of operations](CustomerEntraSyncRunbook.md#order-of-operations-do-in-this-sequence)

For each client you sync (start with your 100-user pilot tenant):

### 2a. Create the security group (in the **customer** tenant)

1. **Private/incognito browser** → https://portal.azure.com → sign in with GDAP so you land in the **customer** tenant (do not open On IT then switch)
2. **Microsoft Entra ID** → left **Manage** → **Groups** → **New group**
3. Type: **Security**, Membership: **Assigned**
4. Name: `On IT Portal` (or `On IT Portal - {Company}`)
5. **Do not add members manually** - portal sync fills the group via Graph on each run
6. Open the group → copy **Object ID** → this is `entra_group_id` in the portal

### 2b. Copy tenant ID

**Entra ID → Overview → Tenant ID** (GUID) → `entra_tenant_id`

### 2c. Grant admin consent for the Portal app in that tenant

**Option 1 - consent URL** (easiest; you must be Global Admin in that tenant):

```
https://login.microsoftonline.com/{customer-tenant-id}/adminconsent?client_id={MICROSOFT_CLIENT_ID}
```

Replace `{customer-tenant-id}` and `{MICROSOFT_CLIENT_ID}` with real GUIDs. Sign in, accept.

**Option 2 - Enterprise applications UI**

1. In **customer tenant**: **Entra ID → Enterprise applications**
2. Find **On IT Portal** (may appear after first consent attempt or first login)
3. **Permissions** → **Grant admin consent for {Customer}**

Repeat for each of your 60 clients (or during each client onboarding).

---

## Step 3: Configure the client in the portal

1. **Admin → Clients → Add Client** - fill SuperOps ID, optional Pax8, then **Create**.
2. **Admin → Clients → Edit** - the **Client setup** checklist on the **right** tracks progress (install-manual: prerequisites, numbered parts, verification). **Dry run sync** / **Sync now** are on the **left** under Microsoft Entra sync.

| Field | Value |
|---|---|
| Entra tenant ID | Customer tenant GUID (required for sync) |
| Entra group ID | Security group Object ID - portal auto-fills members |
| SuperOps Application (client) ID | App registrations → SuperOps → Overview → **Application (client) ID** - **required on Entra ID Free** |
| Entra sync enabled | ✓ |

Save with **Update**. Use **Dry run sync** and **Sync now** on the left, or tick manual steps on the right and **Save checklist**.

---

## Step 4: Server `.env`

Add or set on the server (then `php artisan config:clear`):

```env
ENTRA_SYNC_ENABLED=true
ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=true
ENTRA_SYNC_SUPEROPS_NAME_EXTENSION_ATTRIBUTE=1
# Default true - set false only to pause SuperOps email API writes after domain change
# ENTRA_SYNC_SUPEROPS_EMAIL_ALIGN=false

MICROSOFT_CLIENT_ID=your-portal-app-client-id
MICROSOFT_CLIENT_SECRET=your-portal-app-secret
```

Set `ENTRA_SYNC_SUPEROPS_NAME_EXTENSION_ATTRIBUTE=0` to stop writing SuperOps SCIM names to `extensionAttribute1` (requesters fall back to plain M365 names).

SuperOps **creates** requesters via **SCIM**. Portal also calls SuperOps GraphQL to **align requester primary email** on domain change (`ENTRA_SYNC_SUPEROPS_EMAIL_ALIGN`, default true). See [SuperOpsEntraSync.md](SuperOpsEntraSync.md) for naming and [DomainEmailChange.md](DomainEmailChange.md) for email cutovers.

---

## Step 5: First sync

### Admin UI (after deploy)

**Clients → Edit client → Dry run sync** → check counts → **Sync now**

While sync runs, **Sync now** and **Dry run** return immediately and continue **in the background** (avoids nginx 504). Refresh the page in 1-2 minutes for **Last synced**. Full counts / SuperOps email-align summary land in `entra_sync.last_result.{client}` and activity log - not a long on-page flash.

If a sync is stuck after a timeout: `php artisan portal:release-entra-sync-lock {client-id}`

### CLI (SSH)

```bash
# Production default: queue jobs (then let queue worker run)
php artisan portal:sync-entra-users --client={id}
php artisan portal:sync-entra-users --client={id} --dry-run

# Foreground (debugging)
php artisan portal:sync-entra-users --client={id} --inline
php artisan portal:sync-entra-users --client={id} --dry-run --inline
```

Check **Admin → Users**. Expect licensed users + shared mailboxes from the **whole tenant**. Sync output may show:

- `SuperOps group: +N / -M members` when `entra_group_id` is set
- `SuperOps app: +N / -M users` when `entra_superops_app_id` is set (licensed users + shared mailboxes)
- `SuperOps SSO access: +N / -M active licensed users` when step 08 is complete and `entra_superops_sso_app_id` is set
- `SuperOps last names updated N` when full last name written to `extensionAttribute1`
- `SuperOps SCIM provision requested for N changed user(s)` - provision-on-demand only for changed/newly assigned users; confirm Updates in **Provisioning logs**

---

## Commands reference

```bash
# Queue sync for all enabled clients (hourly schedule does this)
php artisan portal:sync-entra-users

# Queue one client
php artisan portal:sync-entra-users --client=4

# Foreground / preview
php artisan portal:sync-entra-users --client=4 --inline
php artisan portal:sync-entra-users --client=4 --dry-run --inline
```

---

## Offboarding

| Step | System |
|---|---|
| Remove from security group or disable in M365 | Entra (source of truth) |
| Wait for hourly sync or run command / Admin **Sync now** | Portal sets `is_active=false` |
| SuperOps requester | **Automatic** via SCIM when user leaves the group - see [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |

---

## Troubleshooting

| Error | Fix |
|---|---|
| `Entra sync is disabled` | Set `ENTRA_SYNC_ENABLED=true`; `php artisan config:clear` |
| `Failed to obtain Graph token` | Admin consent not granted in **that customer** tenant |
| `Microsoft Graph request failed: 403` | Missing Application permissions or consent not granted in customer tenant |
| `SuperOps group sync failed` / group 403 | Add `GroupMember.ReadWrite.All`; re-consent in customer tenant - see [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| Consent: `GroupMember.ReadWrite.All does not exist in RequiredResourceAccess` | **Refresh Azure page**, grant consent again - see [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| `Microsoft Graph credentials are not configured` | `MICROSOFT_CLIENT_ID` / `SECRET` missing in `.env` |
| Buttons missing on client edit | Branch not deployed; run `migrate`; save tenant + group + sync enabled |
| Email belongs to another client | Duplicate email across clients; resolve manually |
| Domain rename doubled users (old) | Pre-object-id matching bug; run **Sync now** to merge - [DomainEmailChange.md](DomainEmailChange.md) |
| User not in portal after sync | Unlicensed (and not shared mailbox), no valid mail/UPN, or sync not enabled |
| Could not resolve SuperOps enterprise app | Missing `Application.Read.All` or wrong GUID (Object ID instead of client ID) | Add `Application.Read.All` in On IT tenant; re-consent customer; paste Application (client) ID |
| `Permission being assigned was not found` | No app role or blank **Value** on SuperOps app | App registrations → SuperOps → App roles → User role with Value `User` → Sync now |
| App role assignment 403 | Wrong Object ID pasted, or missing `AppRoleAssignment.ReadWrite.All` | Use Application (client) ID; re-consent; `php artisan cache:clear` |
| No `SuperOps group` in sync output | `entra_group_id` empty on client record |
| No `SuperOps app` in sync output | `entra_superops_app_id` empty - only needed on Entra ID Free |
| No `SuperOps SSO access` in sync output | Complete step 08 Client SSO and save `entra_superops_sso_app_id` - only needed on Entra ID Free |
| No `SuperOps last names updated` line | Names already current, or `ENTRA_SYNC_SUPEROPS_NAME_EXTENSION_ATTRIBUTE=0` |
| displayName update 403 | Missing `User.ReadWrite.All` or customer consent - re-consent |
| App role assignment 403 after re-consent | Stale Graph token cached up to 50 min - run `php artisan cache:clear` then sync again (portal auto-retries from next deploy) |
| SuperOps requester plain name | Run portal sync first, then check Entra **Provisioning logs** for **Update** per user - [SuperOpsEntraSync.md](SuperOpsEntraSync.md#requester-display-names) |
| `extensionAttribute1` correct but SuperOps plain; logs show few Updates | Re-run **Sync now** (portal provisions one user per call). Or **Provision on demand** in Entra for one user to confirm mapping |
| Sync already running | Another UI sync or cron job holds the client lock | Wait and retry; lock expires after `ENTRA_SYNC_LOCK_SECONDS` (default 600) |
| Rate limited on Sync now | More than 2 sync requests per minute per client | Wait 60s - `throttle:entra-sync` on admin route |

---

## Related

- [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) - **complete re-do from scratch**
- [Authentication.md](Authentication.md) - portal OAuth app (app #1)
- [SuperOpsEntraSync.md](SuperOpsEntraSync.md) - Sync 1 (SCIM requesters)
- [Deployment.md](Deployment.md) - Plesk deploy and updates
- `app/Services/EntraSync/EntraGroupSyncService.php`

---

## Change log

| Date | Change |
|---|---|
| 2026-08-11 | SuperOps API email align after Sync; object-id upsert; async result (not flash); `ENTRA_SYNC_SUPEROPS_EMAIL_ALIGN` |
| 2026-07-14 | Added separate per-customer Client SSO Application ID and Entra Free direct requester-login assignment |
| 2026-06-25 | Sync now: per-user SCIM provision-on-demand + loading UI; banner text clarifies provision **requested** vs completed |
| 2026-06-25 | Full SuperOps SCIM name in `extensionAttribute1`; Direct `name.familyName` mapping; `entra_superops_app_id`; shared mailboxes on app assign |
| 2026-06-19 | Tenant-wide sync; auto-maintain SuperOps group |
