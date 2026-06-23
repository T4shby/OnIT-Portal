# Entra tenant sync — Sync 2 (portal only)

Sync **portal users** from a customer's Microsoft Entra tenant. Part of the **two-sync** model — see [AccessAndSync.md](AccessAndSync.md).

| Sync | What | Doc |
|---|---|---|
| **1** | M365 → SuperOps requesters (SCIM) | [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| **2** | M365 → Portal users (this doc) | `portal:sync-entra-users` |

**Scope:** All **licensed** M365 users and **shared mailboxes** in the customer tenant. `entra_group_id` is **optional** for portal sync (still used for SCIM group assignment in SuperOps).

---

## Which Entra app? (read this first)

You already have **two** Entra apps. Sync does **not** need a third unless you choose to create one.

| App | Purpose | Protocol | `.env` keys |
|---|---|---|---|
| **On IT Portal** (app #1) | Users sign in to `app.onit.ltd` | OAuth / OIDC | `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET` |
| **SuperOps requester SSO** (app #2) | Users open SuperOps tickets | SAML | Separate — see [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) |
| **Graph sync** | Portal reads group members | Uses app #1 credentials | Same as OAuth by default |

### Recommended: extend the existing Portal OAuth app

**Do not create a new app** unless you want sync isolated from login (unusual).

1. Open the **same** app registration you use for portal login (`MICROSOFT_CLIENT_ID`).
2. Add **Application** (not Delegated) Graph permissions — see [Step 1 below](#step-1-add-graph-permissions-to-the-portal-oauth-app-on-it-tenant).
3. Grant **admin consent in each customer tenant** where you sync — see [Step 2](#step-2-admin-consent-in-each-customer-tenant).

The portal code uses `MICROSOFT_CLIENT_ID` / `MICROSOFT_CLIENT_SECRET` for Graph unless you set optional `ENTRA_SYNC_CLIENT_ID` / `ENTRA_SYNC_CLIENT_SECRET` (dedicated sync app — only if you create one).

**Login is unchanged.** Existing Delegated permissions (`openid`, `profile`, `email`, `User.Read`) stay as they are. Sync adds Application permissions on top.

---

## What sync does

| Action | Behaviour |
|---|---|
| **Licensed** M365 user in tenant | Create or update portal user (`client_user`, `portal_login_enabled=true`, display `Name (User)`) |
| **Shared mailbox** in tenant | Create or update portal user (`portal_login_enabled=false`, display `Name (Shared Mailbox)`) — cannot sign in |
| User **no longer licensed** (not a shared mailbox) | Deactivate portal user (`is_active=false`) if provisioned by sync |
| User **disabled** in Entra | Portal user set inactive |
| **Manual** portal users | Not managed by sync (`provisioned_by = manual`) — skipped |
| **SuperOps requesters** | **Not handled here** — use [SuperOpsEntraSync.md](SuperOpsEntraSync.md) SCIM |

Use security group `On IT Portal - {Company}` for **SuperOps SCIM** assignment. Portal sync reads the **whole tenant** — group ID on the client record is optional.

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

**Cron:** `schedule:run` every minute ([Deployment.md](Deployment.md)). Sync runs **hourly** when enabled.

---

## Step 1: Add Graph permissions to the Portal OAuth app (On IT tenant)

Do this in **your** Entra tenant (where the app registration lives — On IT Technology Partners).

1. [Azure Portal](https://portal.azure.com) → **Microsoft Entra ID** → **App registrations**
2. Open **On IT Portal** (the app whose Client ID is in `MICROSOFT_CLIENT_ID`)
3. **API permissions** → **Add a permission**
4. **Microsoft Graph** → **Application permissions** (not Delegated)
5. Add:
   - `User.Read.All`
   - `LicenseAssignment.Read.All`
   - `MailboxSettings.Read`
   - `Group.Read.All`
6. **Add permissions**
7. **Grant admin consent for On IT Technology Partners** (green tick on your home tenant)

You should now see **two types** of permissions on the app:

| Type | Examples | Used for |
|---|---|---|
| Delegated | `openid`, `User.Read` | User login (unchanged) |
| Application | `User.Read.All`, `LicenseAssignment.Read.All`, `MailboxSettings.Read`, `Group.Read.All` | Background sync + M365 directory |

---

## Step 2: Admin consent in each customer tenant

Application permissions only work in a customer tenant after **that tenant's** admin grants consent.

For each client you sync (start with your 100-user pilot tenant):

### 2a. Create the security group (in the **customer** tenant)

1. Azure Portal → switch directory to **customer tenant** (top-right account picker)
2. **Entra ID → Groups → New group**
3. Type: **Security**
4. Name: `On IT Portal` (or `On IT Portal - {Company}`)
5. Add members who should access the portal
6. Open the group → copy **Object ID** → this is `entra_group_id` in the portal

### 2b. Copy tenant ID

**Entra ID → Overview → Tenant ID** (GUID) → `entra_tenant_id`

### 2c. Grant admin consent for the Portal app in that tenant

**Option 1 — consent URL** (easiest; you must be Global Admin in that tenant):

```
https://login.microsoftonline.com/{customer-tenant-id}/adminconsent?client_id={MICROSOFT_CLIENT_ID}
```

Replace `{customer-tenant-id}` and `{MICROSOFT_CLIENT_ID}` with real GUIDs. Sign in, accept.

**Option 2 — Enterprise applications UI**

1. In **customer tenant**: **Entra ID → Enterprise applications**
2. Find **On IT Portal** (may appear after first consent attempt or first login)
3. **Permissions** → **Grant admin consent for {Customer}**

Repeat for each of your 60 clients (or during each client onboarding).

---

## Step 3: Configure the client in the portal

1. **Admin → Clients → Add Client** — fill SuperOps ID, optional Pax8, then **Create**.
2. **Admin → Clients → Edit** — the **Client setup** checklist on the **right** tracks progress. **Dry run sync** / **Sync now** are on the **left** under Microsoft Entra sync.

| Field | Value |
|---|---|
| Entra tenant ID | Customer tenant GUID (required for sync) |
| Entra group ID | Optional — SCIM group reference; portal sync does not require it |
| Entra sync enabled | ✓ |

Save with **Update**. Use **Dry run sync** and **Sync now** on the left, or tick manual steps on the right and **Save checklist**.

---

## Step 4: Server `.env`

Add or set on the server (then `php artisan config:clear`):

```env
ENTRA_SYNC_ENABLED=true

MICROSOFT_CLIENT_ID=your-portal-app-client-id
MICROSOFT_CLIENT_SECRET=your-portal-app-secret
```

SuperOps requesters use **SCIM** — no portal env vars for that. See [SuperOpsEntraSync.md](SuperOpsEntraSync.md).

---

## Step 5: First sync

### Admin UI (after deploy)

**Clients → Edit client → Dry run sync** → check counts → **Sync now**

### CLI (SSH)

```bash
php artisan portal:sync-entra-users --client={id} --dry-run
php artisan portal:sync-entra-users --client={id}
```

Check **Admin → Users**. Expect users matching group membership.

---

## Commands reference

```bash
# All clients with sync enabled
php artisan portal:sync-entra-users

# One client
php artisan portal:sync-entra-users --client=4

# Preview only
php artisan portal:sync-entra-users --client=4 --dry-run
```

---

## Offboarding

| Step | System |
|---|---|
| Remove from security group or disable in M365 | Entra (source of truth) |
| Wait for hourly sync or run command / Admin **Sync now** | Portal sets `is_active=false` |
| SuperOps requester | **Automatic** via SCIM when user leaves the group — see [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |

---

## Troubleshooting

| Error | Fix |
|---|---|
| `Entra sync is disabled` | Set `ENTRA_SYNC_ENABLED=true`; `php artisan config:clear` |
| `Failed to obtain Graph token` | Admin consent not granted in **that customer** tenant |
| `Microsoft Graph request failed: 403` | Missing Application permissions or consent not granted in customer tenant |
| `Microsoft Graph credentials are not configured` | `MICROSOFT_CLIENT_ID` / `SECRET` missing in `.env` |
| Buttons missing on client edit | Branch not deployed; run `migrate`; save tenant + group + sync enabled |
| Email belongs to another client | Duplicate email across clients; resolve manually |
| User not in portal after sync | Not in group, no valid mail/UPN, or sync not enabled on client |
| User not in SuperOps | Check SCIM provisioning — [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |

---

## Related

- [Authentication.md](Authentication.md) — portal OAuth app (app #1)
- [SuperOpsEntraSync.md](SuperOpsEntraSync.md) — Sync 1 (SCIM requesters)
- [Deployment.md](Deployment.md) — Plesk deploy and updates
- `app/Services/EntraSync/EntraGroupSyncService.php`
