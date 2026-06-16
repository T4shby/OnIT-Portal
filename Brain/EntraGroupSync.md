# Entra group sync (Phase 2)

Sync portal users from a Microsoft Entra **security group** in each customer's tenant. Built for MSPs managing many tenants (e.g. 60 clients, 100+ users per client).

**Branch:** `feature/entra-group-sync` (merge to `main` after testing)

---

## What it does

| Action | Behaviour |
|---|---|
| User **in** the group | Create or update portal user (`client_user`, active if `accountEnabled` in Entra) |
| User **removed** from group | Deactivate portal user (`is_active=false`) if provisioned by sync |
| User **disabled** in Entra | Portal user set inactive |
| **Manual** portal users | Not deactivated by sync (provisioned_by = `manual`) |
| SuperOps requesters | **Not** created automatically (still manual or bulk import) |

---

## One-time: Microsoft Graph app permissions

Use the **same** app registration as the portal OAuth app, or create a dedicated app. Application permissions (not delegated):

| Permission | Why |
|---|---|
| `GroupMember.Read.All` | Read security group members |
| `User.Read.All` | Read `accountEnabled`, mail, display name |

### Admin consent per customer tenant

Because each client has their **own** tenant, Tom (as admin) must grant consent **in each tenant**:

1. Azure Portal → switch to **customer tenant**
2. **Entra ID → Enterprise applications → On IT Portal** (or your Graph app)
3. **Permissions** → **Grant admin consent for {Tenant}**

Or use a consent URL (replace `{tenant-id}` and `{client-id}`):

```
https://login.microsoftonline.com/{tenant-id}/adminconsent?client_id={client-id}
```

Repeat for all 60 tenants (or batch during client onboarding).

---

## Per-client setup (your 100-user tenant)

### 1. Create security group in customer Entra

1. Customer tenant → **Entra ID → Groups → New group**
2. Type: **Security**
3. Name: `On IT Portal` (or `On IT Portal - {Company Name}`)
4. Add all users who should access the portal

Copy the **Object ID** of the group (this is `entra_group_id`).

### 2. Copy tenant ID

**Entra ID → Overview → Tenant ID** (GUID). This is `entra_tenant_id`.

### 3. Portal admin (client record)

**Admin → Clients → Edit client:**

| Field | Value |
|---|---|
| Entra tenant ID | Customer tenant GUID |
| Entra group ID | Security group object ID |
| Entra sync enabled | ✓ |

### 4. Production `.env`

```env
ENTRA_SYNC_ENABLED=true
MICROSOFT_CLIENT_ID=...          # same app as OAuth, with Graph app permissions
MICROSOFT_CLIENT_SECRET=...
# Or dedicated Graph app:
# ENTRA_SYNC_CLIENT_ID=...
# ENTRA_SYNC_CLIENT_SECRET=...
```

### 5. Run migration and first sync

```bash
php artisan migrate
php artisan portal:sync-entra-users --client={id} --dry-run
php artisan portal:sync-entra-users --client={id}
```

Check **Admin → Users** for the client. Expect ~100 users after first run.

### 6. Schedule (production)

Plesk cron (if not already):

```bash
* * * * * /opt/plesk/php/8.3/bin/php /var/www/vhosts/onit.ltd/app.onit.ltd/artisan schedule:run >> /dev/null 2>&1
```

Sync runs **hourly** when `ENTRA_SYNC_ENABLED=true`.

---

## Commands

```bash
# All clients with sync enabled
php artisan portal:sync-entra-users

# One client
php artisan portal:sync-entra-users --client=5

# Preview only
php artisan portal:sync-entra-users --client=5 --dry-run
```

---

## Offboarding

| Step | System |
|---|---|
| Remove from security group **or** disable in M365 | Entra (source of truth) |
| Wait for hourly sync **or** run command manually | Portal sets `is_active=false` |
| Remove SuperOps requester | SuperOps (still manual) |

---

## Troubleshooting

| Error | Fix |
|---|---|
| `Failed to obtain Graph token` | Admin consent not granted in that tenant |
| `Microsoft Graph request failed: 403` | Missing `GroupMember.Read.All` / `User.Read.All` application permissions |
| `Entra sync is disabled` | Set `ENTRA_SYNC_ENABLED=true` |
| Email belongs to another client | Duplicate email across clients; resolve manually |
| User not in portal after sync | Not in group, no valid mail/UPN, or sync not enabled on client |

---

## Related

- [AccessAndSync.md](AccessAndSync.md) strategy and scale
- [NewClientSetupGuide.md](NewClientSetupGuide.md) full client onboarding
- `app/Services/EntraSync/EntraGroupSyncService.php`
