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

- Checklist instructions were updated (manual format: **Before you start**, numbered **Part A/B/C**, **Check your work**)
- Entra sync / group auto-maintain code changed (`eab02ed` and later)

You do **not** need pull for Azure or SuperOps steps — those are outside the portal.

---

## Where each step runs (not all on the portal)

| Checklist step | Primary system | URL / location |
|----------------|----------------|----------------|
| — | **Portal** (before checklist) | Admin → Clients → **Add Client** → Create → Edit opens |
| 01 Link SuperOps | **SuperOps** + Portal | SuperOps MSP console → paste ID on portal |
| 02 Pax8 (optional) | **Pax8** + Portal | app.pax8.com → paste ID on portal |
| 03 Security group | **Azure** (customer tenant) | portal.azure.com — customer directory |
| 04 Admin consent | **Azure** (customer tenant) | Consent URL on portal checklist → Microsoft login |
| 05 SCIM | **SuperOps** + **Azure** (customer) | SuperOps Integrations + Entra enterprise app |
| 06 Client SSO (SAML) | **SuperOps** + **Azure** (customer) | Same Entra app as step 05 |
| 07 Enable sync | **Portal** | app.onit.ltd — left column fields → Save client |
| 08 Run sync | **Portal** | Dry run sync / Sync now buttons on left |
| 09 Test sign-in | **Browser** | app.onit.ltd + SuperOps in incognito |
| 10 Hand off | **Email/ticket** | Tell customer the portal URL |

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
| **3** | Portal | Paste **Entra tenant ID** + **Entra group ID** → Save client |
| **4** | Customer tenant | **Admin consent** for OnIT Portal for Portals (checklist step **04**) |
| **5** | Customer tenant | One app `SuperOps - {Company}` — SCIM + assign group (checklist step **05**) |
| **6** | Same app | Add SAML (Client SSO) — do **not** create a second app (checklist step **06**) |
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
| `User.ReadWrite` | **User.ReadWrite.All** | Set `extensionAttribute1` SuperOps name hints |
| `LicenseAssignment` | **LicenseAssignment.Read.All** | Read all license assignments |
| `MailboxSettings` | **MailboxSettings.Read** | Read all user mailbox settings |
| `Group.Read` | **Group.Read.All** | Read all groups |
| `GroupMember` | **GroupMember.ReadWrite.All** | Read and write all group memberships |
| `AppRoleAssignment` | **AppRoleAssignment.ReadWrite.All** | Assign users to SuperOps enterprise app on Entra ID Free |
| `Application.Read` | **Application.Read.All** | Resolve SuperOps Application (client) ID → enterprise app during sync |
| `Synchronization.ReadWrite` | **Synchronization.ReadWrite.All** | Trigger SCIM provision-on-demand when portal Sync now runs |

5. Click **Add permissions** at the bottom of the panel
6. Back on the main page, click **Grant admin consent for On IT Technology Partners LTD**
7. Click **Yes** on the confirmation popup

### 0.3 Verify success

All **Application** rows must show:

- **Status:** green tick — **Granted for On IT Technology Partners LTD**

Expected **13** Microsoft Graph permissions total:

**Delegated (4):** email, openid, profile, User.Read  
**Application (9):** User.Read.All, User.ReadWrite.All, LicenseAssignment.Read.All, MailboxSettings.Read, Group.Read.All, GroupMember.ReadWrite.All, AppRoleAssignment.ReadWrite.All, Application.Read.All, Synchronization.ReadWrite.All

### 0.4 If consent fails with `GroupMember.ReadWrite.All does not exist in RequiredResourceAccess`

This happened on first deploy (June 2026). **Fix:**

1. **Refresh the browser page** (F5)
2. Confirm all **nine** Application permissions still appear in the table
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
| SuperOps Application (client) ID | App registrations → SuperOps app → Overview → **Application (client) ID** — **not** Object ID. Required on Entra ID Free. |
| Entra sync enabled | ✓ |

Click **Save client**.

---

## Step 4 — Admin consent (customer tenant) — checklist **04**

Must be done in the **customer** tenant as Global Admin (or GDAP with consent rights).

### Option A — Link from portal checklist

1. **Admin → Clients → Edit** → checklist **Step 04 — Portal Graph admin consent**
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

**Re-consent** if you added permissions after an earlier consent — old consent does not include new permissions (e.g. `Application.Read.All`, `AppRoleAssignment.ReadWrite.All`).

---

## Step 5–6 — One SuperOps Entra app (SCIM + SAML) — checklist **05** + **06**

App name: **`SuperOps - {Company}`** (e.g. `SuperOps - Ductec LTD`)

### 5a — SuperOps SCIM tokens

1. SuperOps MSP console → **Integrations → Microsoft Entra ID**
2. **Generate Tokens** → select this client (e.g. **Ductec LTD**)
3. Copy **Tenant URL** and **Secret Token** (Auth Token) — store in password manager; regenerate if exposed

### 5b — Create app and SCIM (customer tenant)

1. **Entra ID → Enterprise applications → New application**
2. **Create your own application** → non-gallery → name `SuperOps - {Company}` → **Create**
3. **Provisioning** → **Provisioning** → Mode: **Automatic**
4. **Admin Credentials:** Authentication method = **Bearer authentication** (default). **Tenant URL** + **Secret Token** (Auth Token) from SuperOps → **Test Connection** → must succeed → **Save**
5. **App role (required on Entra ID Free — portal sync assigns users via Graph):**
   - **App registrations** → open the SuperOps app (same name, e.g. `OnIT X Superops` or `SuperOps - {Company}`)
   - **App roles** → if no enabled role exists, **Create app role**:
     - **Display name:** `User`
     - **Allowed member types:** Users/Groups
     - **Value:** `User` (required — do not leave blank)
     - **Description:** `Default access for SCIM users` (required)
     - **Enable this app role:** ✓ → **Save**
   - If a **User** role already exists: open it → confirm **Value** is set (e.g. `User`) and the role is **enabled**
   - **Remove duplicate roles with blank Value** (keep one role with Value `User`, e.g. "Default access for SCIM users") — portal sync picks the role with Value `User`, not `msiam_access`
6. **SCIM name mapping (SuperOps requester names — required):**
   - **Provisioning → Edit attribute mapping → Provision Microsoft Entra ID Users**
   - **`name.formatted`** → Direct → Source **extensionAttribute1** → Default if null `[displayName]` → Always
   - **`displayName`** → Direct → Source **displayName** → Always → **Save**
   - **Remove** any Join/Expression mapping — garbled names like `(AccountsShared MailboxAccounts)`
   - Do **not** put the expression in "Default value if null" on a Direct mapping — it will not run
7. **Users and groups:**
   - **Entra ID P1:** **Add user/group** → security group `On IT Portal - {Company}` → **Assign** (once — portal sync keeps membership updated)
   - **Entra ID Free:** copy **Application (client) ID** from App registrations → SuperOps app → Overview → portal **SuperOps Application (client) ID** — do **not** use Object ID on that page. Portal sync assigns licensed users; do not add users manually in Azure
7. **Provisioning → Start provisioning** (or wait for cycle)
8. **Portal Sync now** → expect `SuperOps name hints updated N; SuperOps SCIM provisioned N` — requesters show `(User)` / `(Shared Mailbox)` in SuperOps only

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

## Step 7 — Portal sync — checklist **07** + **08**

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
ENTRA_SYNC_SUPEROPS_NAME_EXTENSION_ATTRIBUTE=1
MICROSOFT_CLIENT_ID=<OnIT Portal for Portals client ID>
MICROSOFT_CLIENT_SECRET=<secret>
```

### Portal client fields (Edit → Microsoft Entra sync)

| Field | Ductec example |
|---|---|
| Entra tenant ID | Customer tenant GUID |
| Entra group ID | `On IT Portal - Ductec LTD` group Object ID |
| SuperOps Application (client) ID | `8c46a344-a010-4c78-99b9-df8b9caaba2f` — **required on Entra ID Free** |
| Entra sync enabled | ✓ |

### Run sync in portal UI

1. **Admin → Clients → Edit**
2. Confirm tenant ID, group ID, **SuperOps Application (client) ID** (Free tier), sync enabled
3. **Dry run sync** — expect user counts, `SuperOps group: +N`, `SuperOps app: +N`, `SuperOps name hints updated N`
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
| 3 | SuperOps requesters | **SuperOps → Clients → {Company} → Requesters** — names show `(User)` or `(Shared Mailbox)`; emails match |
| 4 | SAML login | Incognito → `app.onit.ltd` → SuperOps tile → Microsoft sign-in with `@customerdomain` |
| 5 | Joiner | New licensed user → after sync + SCIM cycle → in group, portal, SuperOps |
| 6 | Leaver | Licence removed → portal user deactivated; removed from group → SCIM deprovisions |

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|--------|-----|
| Consent error `GroupMember.ReadWrite.All does not exist in RequiredResourceAccess` | Azure UI lag after adding permission | **Refresh page**, click Grant admin consent again |
| No `SuperOps group` line in sync output | `Entra group ID` empty | Paste group Object ID → Save client |
| Group sync 403 / forbidden | Missing `GroupMember.ReadWrite.All` or customer consent | Step 0 + step 4 (re-consent) |
| Portal users sync, group empty | `ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=false` or consent missing | Set `true`; `php artisan config:clear` |
| Requesters not in SuperOps | App not in scope; **provisioning off**; missing SuperOps Application (client) ID on Free | Step 5b — turn provisioning **ON**; set client ID; Sync now |
| Garbled SuperOps name e.g. `(AccountsShared MailboxAccounts)` | Join Expression still on displayName/name.formatted | Switch to **Direct** mapping per [SuperOpsEntraSync.md](SuperOpsEntraSync.md); Sync now |
| Requester plain name (no suffix) | `name.formatted` not mapped from extensionAttribute1 | Direct map **name.formatted** ← extensionAttribute1; Sync now |
| Could not resolve SuperOps enterprise app | Missing `Application.Read.All` or wrong GUID pasted | Add **Application.Read.All** in On IT tenant (step 0), re-consent customer (step 4), `cache:clear`. Use Application (client) ID — not App registration Object ID |
| `Permission being assigned was not found on application` | SuperOps app has no **App role** (or **Value** left blank) | App registrations → SuperOps → **App roles** → Create or edit: Display name `User`, Value `User`, Description `Default access for SCIM users`, Users/Groups, Enable → Save → Sync now |
| App role assignment 403 | Wrong Object ID pasted, or missing `AppRoleAssignment.ReadWrite.All` | Use Application (client) ID; re-consent; `php artisan cache:clear` |
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
| SuperOps app name | `OnIT X Superops` (or `SuperOps - Ductec LTD`) |
| SuperOps Application (client) ID | `8c46a344-a010-4c78-99b9-df8b9caaba2f` (App registrations → OnIT X Superops → Overview) |
| Entra ID tier | Free — portal assigns users via Application (client) ID + `Application.Read.All` |
| Requester name format | `Name (User)` or `Name (Shared Mailbox)` in SuperOps only — via SCIM attribute mapping |

Existing SuperOps requesters: leave them; SCIM matches by email. Run **Sync now** to patch plain names, then SCIM cycle.

---

## Change log

| Date | Change |
|------|--------|
| 2026-06-25 | SuperOps App role step (Value `User`) for Entra ID Free; Application (client) ID on portal |
| 2026-06-24 | In-app checklist manual format; step numbers aligned (05 consent, 06 SCIM, 07 SAML) |
| 2026-06-19 | Auto-maintain group via `GroupMember.ReadWrite.All`; single SuperOps app SCIM+SAML; consent refresh fix documented |
| 2026-06-19 | Full click-by-click runbook for re-do from scratch |
