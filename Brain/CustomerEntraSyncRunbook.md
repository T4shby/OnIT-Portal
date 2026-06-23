# Customer Entra sync — complete runbook (re-do from scratch)

**Use this** when onboarding a new MSP customer (e.g. Ductec LTD) or if you need to rebuild Entra sync, SCIM, and SSO from zero.

**In-app wizard:** **Admin → Clients → Edit** — checklist on the **right** (full instructions per step). **Not all steps are on the portal** — see table below.

**Related:** [AccessAndSync.md](AccessAndSync.md) · [EntraGroupSync.md](EntraGroupSync.md) · [SuperOpsEntraSync.md](SuperOpsEntraSync.md)

---

## Do I need git pull on the server?

**Yes — on production (`app.onit.ltd`), pull after code is pushed to `main`.** You do not push from the server.

```bash
cd /var/www/vhosts/onit.ltd/app.onit.ltd
export PATH="/opt/plesk/php/8.3/bin:$PATH"
export COMPOSER_ALLOW_SUPERUSER=1
git pull origin main
rm -f public/hot
composer install --no-dev --optimize-autoloader
php artisan config:clear
php artisan view:clear
php artisan optimize
```

Pull when:

- Checklist instructions were updated (you will see longer steps and “Where:” lines on each step)
- Entra sync / group auto-maintain code changed (`eab02ed` and later)

You do **not** need pull for Azure or SuperOps steps — those are outside the portal.

---

## Where each step runs (not all on the portal)

| Checklist step | Primary system | URL / location |
|----------------|----------------|----------------|
| 01 Portal client record | **Portal** | https://app.onit.ltd — Admin → Clients → Edit |
| 02 Link SuperOps | **SuperOps** + Portal | SuperOps MSP console → paste ID on portal |
| 03 Pax8 (optional) | **Pax8** + Portal | app.pax8.com → paste ID on portal |
| 04 Security group | **Azure** (customer tenant) | portal.azure.com — Ductec directory |
| 05 SCIM | **SuperOps** + **Azure** (customer) | SuperOps Integrations + Entra enterprise app |
| 06 Admin consent | **Azure** (customer tenant) | Consent URL on portal checklist → Microsoft login |
| 07 Client SSO (SAML) | **SuperOps** + **Azure** (customer) | Same Entra app as step 05 |
| 08 Enable sync | **Portal** | app.onit.ltd — left column fields → Update |
| 09 Run sync | **Portal** | Dry run sync / Sync now buttons on left |
| 10 Test sign-in | **Browser** | app.onit.ltd + SuperOps in incognito |
| 11 Hand off | **Email/ticket** | Tell customer the portal URL |

**Once per platform (not per client):** Graph permissions on **OnIT Portal for Portals** in **On IT** tenant — Step 0 below.

---

## What you are building (simplest path)

Per **customer** M365 tenant:

| # | Object | Who creates | Manual ongoing work |
|---|--------|-------------|---------------------|
| 1 | Empty security group `On IT Portal - {Company}` | On IT technician (GDAP) | **None** — portal sync fills members |
| 2 | One Entra enterprise app `SuperOps - {Company}` | On IT technician | **None** — SCIM + SAML on same app |
| 3 | Group assigned to that app **once** | On IT technician | **None** |
| 4 | Portal client record (tenant ID, group ID, sync on) | On IT technician (portal) | Dry run → Sync now |

**Separate (On IT tenant, once per platform):** app registration **OnIT Portal for Portals** (OAuth login + Graph sync). Consented into each customer tenant.

**You do not:** bulk-add users to the group, run PowerShell scripts, or create two SuperOps Entra apps unless single-app fails validation.

---

## Architecture (why it looks redundant)

```
Customer M365 tenant
        │
        ├──► Portal sync (our code, Graph)
        │         reads whole tenant → portal users
        │         writes group membership → SuperOps SCIM group
        │
        └──► SuperOps SCIM (Entra native)
                  group members only → SuperOps requesters
```

The **group** exists because Entra SCIM only provisions users **assigned to the enterprise app** (directly or via group). SuperOps SCIM does not talk to our portal. Portal auto-maintain removes manual membership work.

---

## Order of operations (do in this sequence)

| Step | Where | What |
|------|-------|------|
| **0** | On IT tenant | Add Graph **Application** permissions + grant consent (once per platform) |
| **1** | Portal | Client record, SuperOps Account ID |
| **2** | Customer tenant | Create **empty** security group → copy Object ID |
| **3** | Portal | Paste **Entra tenant ID** + **Entra group ID** → Update |
| **4** | Customer tenant | **Admin consent** for OnIT Portal for Portals (checklist step 06) |
| **5** | Customer tenant | One app `SuperOps - {Company}` — SCIM + assign group |
| **6** | Same app | Add SAML (Client SSO) — do **not** create a second app |
| **7** | Portal | Enable Entra sync → Dry run → Sync now |
| **8** | Test | Group members in Entra, requesters in SuperOps, SAML login |

**Step 0 before step 4** — customer consent must include `GroupMember.ReadWrite.All` if that permission was added after an earlier consent (re-consent required).

---

## Step 0 — Graph permissions (On IT tenant, one-time)

Sign in to Azure as `@onit.ltd`. Stay in **On IT Technology Partners LTD** — **not** the customer tenant.

### 0.1 Open the correct app registration

1. Go to [https://portal.azure.com](https://portal.azure.com)
2. Search **Microsoft Entra ID** → open it
3. Left menu → **App registrations**
4. Open **OnIT Portal for Portals** (production name; Client ID must match server `MICROSOFT_CLIENT_ID`)
5. Left menu → **API permissions**

You should already see **Delegated** permissions for login: `email`, `openid`, `profile`, `User.Read`.

### 0.2 Add Application permissions (not Delegated)

1. Click **+ Add a permission**
2. In the right panel, click **Microsoft Graph** (blue hexagon under Commonly used Microsoft APIs)
3. Click the **Application permissions** tab (on the **right** — not Delegated)
4. In **Search permissions**, find and tick each row below:

| Search for | Tick this permission | Description shown |
|------------|----------------------|-------------------|
| `User.Read.All` | **User.Read.All** | Read all users' full profiles |
| `LicenseAssignment` | **LicenseAssignment.Read.All** | Read all license assignments |
| `MailboxSettings` | **MailboxSettings.Read** | Read all user mailbox settings |
| `Group.Read` | **Group.Read.All** | Read all groups |
| `GroupMember` | **GroupMember.ReadWrite.All** | Read and write all group memberships |

5. Click **Add permissions** at the bottom of the panel
6. Back on the main page, click **Grant admin consent for On IT Technology Partners LTD**
7. Click **Yes** on the confirmation popup

### 0.3 Verify success

All **Application** rows must show:

- **Status:** green tick — **Granted for On IT Technology Partners LTD**

Expected **9** Microsoft Graph permissions total:

**Delegated (4):** email, openid, profile, User.Read  
**Application (5):** User.Read.All, LicenseAssignment.Read.All, MailboxSettings.Read, Group.Read.All, GroupMember.ReadWrite.All

### 0.4 If consent fails with `GroupMember.ReadWrite.All does not exist in RequiredResourceAccess`

This happened on first deploy (June 2026). **Fix:**

1. **Refresh the browser page** (F5)
2. Confirm all five Application permissions still appear in the table
3. Click **Grant admin consent for On IT Technology Partners LTD** again

That was enough — no manifest edit, no PowerShell. If it still fails after refresh, wait 2–3 minutes (Azure propagation) and retry. Only then consider removing and re-adding the permission via **Add a permission** again.

---

## Step 2 — Empty security group (customer tenant)

1. Azure Portal → top-right directory picker → switch to **customer tenant** (e.g. Ductec Ltd)
2. **Microsoft Entra ID** → **Groups** → **New group**
3. Fill in:
   - **Group type:** Security
   - **Group name:** `On IT Portal - {Company}` (e.g. `On IT Portal - Ductec LTD`)
   - **Membership type:** Assigned
4. **Leave Members empty** — do not add anyone
5. Click **Create**
6. Open the new group → **Overview** → copy **Object ID** (GUID)
7. **Entra ID → Overview** → copy **Tenant ID** (GUID) if not already on the portal client

---

## Step 3 — Portal client fields

**Admin → Clients → Edit {Company}**

| Field | Value |
|-------|--------|
| Entra Tenant ID | Customer tenant GUID |
| Entra Group ID | Group Object ID from step 2 |
| Entra sync enabled | ✓ |

Click **Update**.

---

## Step 4 — Admin consent (customer tenant)

Must be done in the **customer** tenant as Global Admin (or GDAP with consent rights).

### Option A — Link from portal checklist

1. **Admin → Clients → Edit** → checklist **Step 06 — Portal Graph admin consent**
2. Open the consent URL (or send to customer admin)
3. Confirm the sign-in page shows the **customer** tenant name (not On IT)
4. Review permissions → **Accept**
5. Success page confirms consent

### Option B — Manual URL

```
https://login.microsoftonline.com/{CUSTOMER-TENANT-ID}/adminconsent?client_id={PORTAL-APP-CLIENT-ID}
```

- `{CUSTOMER-TENANT-ID}` = Entra Tenant ID on the client form  
- `{PORTAL-APP-CLIENT-ID}` = **OnIT Portal for Portals** → App registrations → **Application (client) ID**

### Verify in customer tenant

1. **Microsoft Entra ID** → **Enterprise applications** → search **OnIT Portal for Portals** (or **On IT Portal**)
2. **Permissions** → all Application permissions show **Granted**

**Re-consent** if you added `GroupMember.ReadWrite.All` after an earlier consent — old consent does not include new permissions.

---

## Step 5–6 — One SuperOps Entra app (SCIM + SAML)

App name: **`SuperOps - {Company}`** (e.g. `SuperOps - Ductec LTD`)

### 5a — SuperOps SCIM tokens

1. SuperOps MSP console → **Integrations → Microsoft Entra ID**
2. **Generate Tokens** → select this client (e.g. **Ductec LTD**)
3. Copy **Tenant URL** and **Secret Token** (Auth Token) — store in password manager; regenerate if exposed

### 5b — Create app and SCIM (customer tenant)

1. **Entra ID → Enterprise applications → New application**
2. **Create your own application** → non-gallery → name `SuperOps - {Company}` → **Create**
3. **Provisioning** → **Provisioning** → Mode: **Automatic**
4. **Admin Credentials:** Tenant URL + Secret Token from SuperOps → **Test Connection** → must succeed → **Save**
5. **Users and groups** → **Add user/group** → select security group `On IT Portal - {Company}` → **Assign**
6. **Provisioning → Start provisioning** (or wait for cycle)

### 6 — SAML on the **same** app (do not create a second app)

1. SuperOps → **Settings → Requester Login → SSO Protected → Client SSO** → **+ Configuration** for this client
2. Copy **Entity ID** and **Consumer Service URL** (Reply URL)
3. Customer Entra → open **`SuperOps - {Company}`** (same app as step 5)
4. **Single sign-on → SAML** → **Edit Basic SAML Configuration**
   - **Identifier (Entity ID):** from SuperOps  
   - **Reply URL:** Consumer Service URL from SuperOps  
   - Save
5. **Attributes & Claims** → three additional claims, **namespace empty** on each:

| Claim name | Source attribute |
|------------|------------------|
| `email` | `user.mail` (or `user.userprincipalname` if no mailbox) |
| `firstname` | `user.givenname` |
| `lastname` | `user.surname` |

6. **SAML Certificates** → download **Certificate (Base64)** — paste body only (no `BEGIN`/`END` lines) into SuperOps Client SSO
7. SuperOps Client SSO → **IDP Login URL** = Entra app **Overview → Login URL** (ends in `/saml2`) → Save
8. Group already assigned from step 5 — no second assignment

**Legacy fallback:** only if SCIM breaks after SAML on same app — use two apps (`SuperOps Provisioning - {Company}` + `SuperOps SSO - {Company}`), both assigned the same group.

---

## Step 7 — Portal sync

### Server deploy (after `git pull`)

```bash
cd /var/www/vhosts/onit.ltd/app.onit.ltd
export PATH="/opt/plesk/php/8.3/bin:$PATH"
export COMPOSER_ALLOW_SUPERUSER=1

git pull origin main
rm -f public/hot
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan view:clear
php artisan optimize
```

Optional PHP-FPM reload (Plesk): **Domains → onit.ltd → PHP Settings** or `systemctl reload plesk-php83-fpm`

### Server `.env`

```env
ENTRA_SYNC_ENABLED=true
ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=true
MICROSOFT_CLIENT_ID=<OnIT Portal for Portals client ID>
MICROSOFT_CLIENT_SECRET=<secret>
```

### Run sync in portal UI

1. **Admin → Clients → Edit**
2. Confirm tenant ID, group ID, sync enabled
3. **Dry run sync** — expect user counts and `SuperOps group: +N / -0 members`
4. **Sync now**

### CLI alternative

```bash
php artisan portal:sync-entra-users --client={id} --dry-run
php artisan portal:sync-entra-users --client={id}
```

---

## Step 8 — Validation (Ductec / any client)

| # | Check | Pass when |
|---|--------|-----------|
| 1 | Entra group members | **Groups → On IT Portal - {Company} → Members** lists licensed users + shared mailboxes without manual adds |
| 2 | SCIM logs | **Enterprise app → Provisioning → Provisioning logs** — users synced, no errors |
| 3 | SuperOps requesters | **SuperOps → Clients → {Company} → Requesters** — emails match; existing requesters not duplicated |
| 4 | SAML login | Incognito → `app.onit.ltd` → SuperOps tile → Microsoft sign-in with `@customerdomain` |
| 5 | Joiner | New licensed user → after sync + SCIM cycle → in group, portal, SuperOps |
| 6 | Leaver | Licence removed → portal user deactivated; removed from group → SCIM deprovisions |

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|--------|-----|
| Consent error `GroupMember.ReadWrite.All does not exist in RequiredResourceAccess` | Azure UI lag after adding permission | **Refresh page**, click Grant admin consent again |
| No `SuperOps group` line in sync output | `Entra group ID` empty | Paste group Object ID → Update |
| Group sync 403 / forbidden | Missing `GroupMember.ReadWrite.All` or customer consent | Step 0 + step 4 (re-consent) |
| Portal users sync, group empty | `ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=false` or consent missing | Set `true`; `php artisan config:clear` |
| Requesters not in SuperOps | Group not assigned to SCIM app; provisioning off | Step 5b |
| SAML works, SCIM does not (or reverse) | Rare single-app conflict | Legacy two-app fallback |
| Wrong tenant on consent page | Signed into On IT instead of customer | Directory picker top-right |

---

## Entra apps per customer (count)

| App | Tenant | Purpose |
|-----|--------|---------|
| **OnIT Portal for Portals** | On IT (registered); consented in customer | Portal login + Graph sync |
| **SuperOps - {Company}** | Customer | SCIM + Client SSO (default) |

**Not per customer:** On IT Global/technician SSO apps, portal OAuth registration itself.

---

## Reference — Ductec LTD (pilot)

| Item | Value |
|------|--------|
| SuperOps Account ID | `3425667307281944576` (verify in SuperOps URL) |
| Entra Tenant ID | On client record in portal |
| Group name | `On IT Portal - Ductec LTD` |
| SuperOps app name | `SuperOps - Ductec LTD` |
| Entra ID tier | Free (Assigned groups only — portal auto-maintain handles membership) |

Existing SuperOps requesters: leave them; SCIM matches by email.

---

## Change log

| Date | Change |
|------|--------|
| 2026-06-19 | Auto-maintain group via `GroupMember.ReadWrite.All`; single SuperOps app SCIM+SAML; consent refresh fix documented |
| 2026-06-19 | Full click-by-click runbook for re-do from scratch |
