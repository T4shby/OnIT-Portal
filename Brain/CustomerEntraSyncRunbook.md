# Customer Entra sync — complete runbook (re-do from scratch)

**Use this** when onboarding a new MSP customer or if you need to rebuild Entra sync, SCIM, and SSO from zero.

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
| 02 Pax8 (or skip) | **Pax8** + Portal | app.pax8.com → paste ID on portal |
| 03 Portal group + Entra IDs | **Azure** (customer tenant) | portal.azure.com — customer directory |
| 04 Portal Graph Accept | **On IT technician** + customer tenant via GDAP | Orange Accept button on checklist |
| 05 SCIM tokens | **SuperOps** | Generate Tokens for this client |
| 06 SCIM app | **Azure** (customer) | Create `SuperOps - {Company}` + Test Connection |
| 07 SCIM mapping + start | **Azure** + Portal | Mappings; P1 assign group / Free paste app ID |
| 08 SuperOps Client SSO | **On IT technician** + SuperOps + customer tenant via GDAP | Generate client values; create customer SAML app; P1 group / Free SSO app ID |
| 09 Enable sync | **Portal** | Left column → Entra sync enabled → Save client |
| 10 Dry run / Sync now | **Portal** | Left buttons under the form |
| 11 Test as customer | **Browser** | Incognito customer work email |
| 12 Hand off | **Email/ticket** | Tell customer the portal URL |

**Once per platform (not per client):** Graph permissions on **OnIT Portal for Portals** in **On IT** tenant — Step 0 below.

---

## What you are building (simplest path)

Per **customer** M365 tenant:

| # | Object | Who creates | Manual ongoing work |
|---|--------|-------------|---------------------|
| 1 | Empty security group `On IT Portal - {Company}` | On IT technician (GDAP) | **None** — portal sync fills members |
| 2 | One Entra enterprise app `SuperOps - {Company}` | On IT technician | **None** — SCIM provisioning only on this app |
| 3 | SuperOps app scope | On IT technician | **P1:** assign group to app once in Azure. **Free:** paste Application (client) ID on portal — portal assigns users on sync |
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
                  P1: group members on app  →  SuperOps requesters
                  Free: app-assigned users  →  SuperOps requesters
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
| **8** | On IT technician + customer tenant via GDAP | Checklist **08** — configure SuperOps Client SSO; P1 assign Portal group / Free save SSO app ID for Sync now |
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
| `User.ReadWrite` | **User.ReadWrite.All** | Write full SuperOps SCIM name to `extensionAttribute1` |
| `LicenseAssignment` | **LicenseAssignment.Read.All** | Read all license assignments |
| `MailboxSettings` | **MailboxSettings.Read** | Read all user mailbox settings |
| `Group.Read` | **Group.Read.All** | Read all groups |
| `Group` | **Group.ReadWrite.All** | Create portal security group on Connect Microsoft |
| `GroupMember` | **GroupMember.ReadWrite.All** | Read and write all group memberships |
| `AppRoleAssignment` | **AppRoleAssignment.ReadWrite.All** | Assign users/groups to SuperOps enterprise apps |
| `Application.Read` | **Application.Read.All** | Resolve SuperOps Application (client) ID → enterprise app during sync |
| `Application.ReadWrite` | **Application.ReadWrite.All** | Create SuperOps SCIM + Client SSO non-gallery apps + App role User on Connect |
| `Synchronization.ReadWrite` | **Synchronization.ReadWrite.All** | Trigger SCIM provision-on-demand when portal Sync now runs |
| `SecurityEvents` | **SecurityEvents.Read.All** | Microsoft Secure Score on client Dashboard / Reports (M365 column) |
| `AuditLog` | **AuditLog.Read.All** | MFA registration coverage (auth methods report) |
| `Reports.Read` | **Reports.Read.All** | MFA registration coverage (auth methods report) |

5. Click **Add permissions** at the bottom of the panel
6. Back on the main page, click **Grant admin consent for On IT Technology Partners LTD**
7. Click **Yes** on the confirmation popup

### 0.3 Verify success

All **Application** rows must show:

- **Status:** green tick — **Granted for On IT Technology Partners LTD**

Expected Microsoft Graph permissions:

**Delegated (4):** email, openid, profile, User.Read  
**Application (14):** User.Read.All, User.ReadWrite.All, LicenseAssignment.Read.All, MailboxSettings.Read, Group.Read.All, **Group.ReadWrite.All**, GroupMember.ReadWrite.All, AppRoleAssignment.ReadWrite.All, Application.Read.All, **Application.ReadWrite.All**, Synchronization.ReadWrite.All, **SecurityEvents.Read.All**, **AuditLog.Read.All**, **Reports.Read.All**

> **Connect Microsoft** (checklist 03/04) and customer **re-consent** only pick up permissions that already exist on the app in the **On IT** tenant. After adding or changing Application permissions: (1) Grant consent in On IT, (2) **re-run Accept** once per customer — do **not** delete the client record or SuperOps Entra apps.

### 0.3a Existing customers after a platform permission add (e.g. Secure Score / MFA)

You do **not** need to delete clients, wipe SCIM, or redo SuperOps Client SSO.

1. Complete Step 0.2–0.3 in the **On IT** tenant (new rows + Grant admin consent for On IT).
2. **Batch re-consent every linked tenant** (private/GDAP into each customer — one Accept per tenant):

   - **Staff UI (preferred):** Admin → Clients → **Graph re-consent** — Accept links for every active tenant.
   - Per client Edit → **Re-consent Graph permissions**.
   - Optional server list: `php artisan portal:graph-reconsent-urls --markdown --active`

   Do **not** use **Retry Graph setup** unless app IDs are missing.

3. After Accept, wait a few minutes for Graph; force M365 insights refresh (prewarm / visit Organisation). Secure Score and MFA appear only when consent includes the three security report permissions.
4. Licence util and SuperOps keep working without re-consent; only posture metrics stay hidden.

### 0.4 If consent fails with `GroupMember.ReadWrite.All does not exist in RequiredResourceAccess`

This happened on first deploy (June 2026). **Fix:**

1. **Refresh the browser page** (F5)
2. Confirm all Application permissions still appear in the table
3. Click **Grant admin consent for On IT Technology Partners LTD** again

That was enough — no manifest edit, no PowerShell. If it still fails after refresh, wait 2–3 minutes (Azure propagation) and retry. Only then consider removing and re-adding the permission via **Add a permission** again.

---

## Step 2 — Empty security group (customer tenant)

1. **Private/incognito browser** → https://portal.azure.com → sign in with GDAP so you land in the **customer** tenant. Do **not** open On IT Technology Partners LTD first and switch.
2. **Microsoft Entra ID** → left **Manage** → **Groups** → **New group**
3. Fill in:
   - **Group type:** Security
   - **Group name:** `On IT Portal - {Company}`
   - **Membership type:** Assigned
4. **Leave Members empty** — do not add anyone
5. Click **Create**
6. Open the new group → **Overview** → copy **Object ID** (GUID)
7. Entra left **Overview** → copy **Tenant ID** (GUID) if not already on the portal client

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

**Actor:** the On IT technician does this on the customer’s behalf using GDAP. Do not send the consent URL or task to the customer.

### Option A — Link from portal checklist

1. **Admin → Clients → Edit** → checklist **Step 04 — Portal Graph admin consent**
2. On IT technician opens the consent URL using delegated / GDAP access
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

## Step 5–7 — SuperOps SCIM app only — checklist **05** + **06** + **07**

App name: **`SuperOps - {Company}`**

### 5a — SuperOps SCIM tokens

1. SuperOps MSP console → **Integrations → Microsoft Entra ID**
2. **Generate Tokens** → select this SuperOps client
3. Copy **Tenant URL** and **Secret Token** (Auth Token) — store in password manager; regenerate if exposed

### 5b — Create app and SCIM (customer tenant)

1. **Entra ID → Enterprise applications → New application**
2. **Create your own application** → non-gallery → name `SuperOps - {Company}` → **Create**
3. **Provisioning** → **Provisioning** → Mode: **Automatic**
4. **Admin Credentials:** Authentication method = **Bearer authentication** (default). **Tenant URL** + **Secret Token** (Auth Token) from SuperOps → **Test Connection** → must succeed → **Save**
5. **App role (required on Entra ID Free — portal sync assigns users via Graph):**
   - **App registrations** → open the SuperOps app (same name: `SuperOps - {Company}`)
   - **App roles** → if no enabled role exists, **Create app role**:
     - **Display name:** `User`
     - **Allowed member types:** Users/Groups
     - **Value:** `User` (required — do not leave blank)
     - **Description:** `Default access for SCIM users` (required)
     - **Enable this app role:** ✓ → **Save**
   - If a **User** role already exists: open it → confirm **Value** is set (e.g. `User`) and the role is **enabled**
   - **Remove duplicate roles with blank Value** (keep one role with Value `User`, e.g. "Default access for SCIM users") — portal sync picks the role with Value `User`, not `msiam_access`
6. **SCIM name mapping (all Direct — no Expression):**
   - **name.givenName** → Direct → `givenName`
   - **name.familyName** → Direct → `extensionAttribute1` → Default if null `[surname]`
   - **name.formatted** → Direct → `displayName`
   - Do **not** put the expression in "Default value if null" on a Direct mapping — it will not run
7. **Users and groups:**
   - **Entra ID P1:** **Add user/group** → security group `On IT Portal - {Company}` → **Assign** (once — portal sync keeps membership updated)
   - **Entra ID Free:** copy **Application (client) ID** from App registrations → SuperOps app → Overview → portal **SuperOps Application (client) ID** — do **not** use Object ID on that page. Portal sync assigns licensed users; do not add users manually in Azure
7. **Provisioning → Start provisioning** (or wait for cycle)
8. **Portal Sync now** → expect `SuperOps last names updated N; SuperOps SCIM provision requested for N user(s)` — confirm **Update** per user in Entra **Provisioning logs**; requesters show `(User Mailbox)` / `(Shared Mailbox)` in SuperOps only

### 8 — SuperOps requester Client SSO — checklist **08**

On IT uses customer-specific **Client SSO** so customer identities remain in their own Entra tenant with no On IT B2B guests. Full detail: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).

**Happy path (in-app step 08):**

1. Entra app shell `SuperOps Requester SSO - {Company}` + app ID + P1 group assign already come from Connect/bootstrap when Graph works (shown as **Already done automatically**).
2. SuperOps → Requester Login → SSO Protected → Client SSO → **+ Configuration** / this customer → copy Entity ID + Consumer Service URL (SuperOps has no API for these).
3. Portal form → **Wire SuperOps into Microsoft Entra** → paste returned Login URL + Base64 certificate into SuperOps Step 3 → Save.
4. Free: **Sync now** assigns users via saved `entra_superops_sso_app_id`. Incognito test with a customer work email.

**Only if Connect/wire fails:** create the SAML enterprise app by hand in customer Entra, or set Identifier/Reply URL under Single sign-on → SAML — recovery block on the live step. Do **not** put SAML on the SCIM app `SuperOps - {Company}`.

---

## Step 9–10 — Portal sync — checklist **09** + **10**

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

| Field | Where it comes from |
|---------|--------|
| Entra tenant ID | Customer Azure Overview |
| Entra group ID | `On IT Portal - {Company}` group Object ID |
| SuperOps Application (client) ID | App registrations → `SuperOps - {Company}` → Application (client) ID (required on Free) |
| Entra sync enabled | Tick after prerequisites are set |

### Run sync in portal UI

1. **Admin → Clients → Edit**
2. Confirm tenant ID, group ID, **SuperOps Application (client) ID** (Free tier), sync enabled
3. **Dry run sync** — expect user counts, `SuperOps group: +N`, `SuperOps app: +N`, `SuperOps last names updated N`
4. **Sync now** — starts sync **in the background** after redirect (safe to close the browser). Refresh Edit to see **Last synced**. For a live count summary in the terminal, use `php artisan portal:sync-entra-users --client={id}`. Check Entra **Provisioning logs** for **Update** entries after sync completes.

### CLI alternative

```bash
php artisan portal:sync-entra-users --client={id} --dry-run
php artisan portal:sync-entra-users --client={id}
```

---

## Step 8 — Validation

| # | Check | Pass when |
|---|--------|-----------|
| 1 | Entra group members | **Groups → On IT Portal - {Company} → Members** lists licensed users + shared mailboxes without manual adds |
| 2 | SCIM logs | **Enterprise app → Provisioning → Provisioning logs** — users synced, no errors |
| 3 | SuperOps requesters | **SuperOps → Clients → {Company} → Requesters** — names show `(User Mailbox)` or `(Shared Mailbox)`; emails match |
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
| Requesters not in SuperOps; Integration Health SuperOps SCIM red; step 07 **Failed** | Sync 1 job missing/stopped, **or** Entra SCIM app has **0 provisioning templates** (created via bare `POST /applications`) | `--check` first. If **0 templates** → Edit Client **Retry Graph setup** (auto recreate via template instantiate) → **Apply SCIM**. If templates OK + BaseAddress → **Retry SCIM export** / `portal:repair-superops-scim`. See [SuperOpsEntraSync.md](SuperOpsEntraSync.md#scim-job-missing--portal-sync-looks-fine-but-superops-has-gaps) |
| Apply SCIM / Retry SCIM: “zero SCIM provisioning templates” | Wrong Entra app create path | **Retry Graph setup** then Apply — do not delete client; do not Azure UI recreate |
| Garbled SuperOps name e.g. `(AccountsShared MailboxAccounts)` | Join Expression still on displayName/name.formatted | Switch to **Direct** mapping per [SuperOpsEntraSync.md](SuperOpsEntraSync.md); Sync now |
| Requester plain name (no suffix) | `name.familyName` not Direct from `extensionAttribute1` | Direct map **name.familyName** ← extensionAttribute1 (default `[surname]`); Sync now |
| `extensionAttribute1` set (e.g. `Smith (User Mailbox)`) but SuperOps still plain | Entra **Provisioning logs** missing **Update** for that user | Sync now (portal provisions one user per call) or **Provision on demand** in Entra for that user |
| Connect bootstrap: tenant/group OK but SCIM/SSO apps fail with Graph **404** `Request_ResourceNotFound` on app/roles | SP object id from create/instantiate not yet (or never) GET-able; stale SP id reused | Code waits/re-resolves SP by **appId**, retries role resolve, falls back to Application appRoles. Deploy latest → **Retry Graph setup**. If still fails: Entra → Enterprise app → Users and groups → assign portal group. |
| Connect right after Accept: 401 IdentityNotFound / 403 insufficient privileges | Consent SP / scopes not live yet | Bootstrap waits + retries; if still incomplete use **Retry Graph setup**. Not a failed login. |
| Could not resolve SuperOps enterprise app | Missing `Application.Read.All`, wrong GUID, or half-deleted SCIM app after bad reset | Prefer **Retry Graph setup** (recreate). Else Application.Read.All + re-consent. Use Application (client) ID — not Object ID |
| `Permission being assigned was not found on application` | SuperOps app has no **App role** (or **Value** left blank) | App registrations → SuperOps → **App roles** → Create or edit: Display name `User`, Value `User`, Description `Default access for SCIM users`, Users/Groups, Enable → Save → Sync now |
| App role assignment 403 | Wrong Object ID pasted, or missing `AppRoleAssignment.ReadWrite.All` | Use Application (client) ID; re-consent; `php artisan cache:clear` |
| SAML works, SCIM does not (or reverse) | Rare single-app conflict | Legacy two-app fallback |
| Wrong tenant on consent page | Signed into On IT instead of customer | Directory picker top-right |

---

## Entra apps per customer (count)

| App | Tenant | Purpose |
|-----|--------|---------|
| **OnIT Portal for Portals** | On IT (registered); consented in customer | Portal login + Graph sync |
| **SuperOps - {Company}** | Customer | SCIM provisioning only |
| **SuperOps Requester SSO - {Company}** | Customer | Client SSO SAML — P1 assign Portal group / Free portal assigns users |

**Not per customer:** On IT technician SSO and portal OAuth registration itself.

Existing SuperOps requesters: leave them; SCIM matches by email. Run **Sync now** to patch plain names, then SCIM cycle.

---

## Change log

| Date | Change |
|------|--------|
| 2026-08-13 | SCIM 0-templates incident: Retry Graph recreate + Apply; troubleshooting rows for Failed step 07 / IH SuperOps SCIM |
| 2026-08-10 | Staff **Graph re-consent** page under Clients (Accept links; no artisan required) |
| 2026-08-10 | Graph **Application (14):** add `SecurityEvents.Read.All`, `AuditLog.Read.All`, `Reports.Read.All` for Secure Score + MFA on client home; existing customers **re-consent only** (§0.3a); `portal:graph-reconsent-urls` batch list |
| 2026-08-10 | Bootstrap flash no longer hard-codes SCIM/SSO “Still required” when checklist already done |
| 2026-08-06 | Step 08 + all 12: automation-first guide; Wire SuperOps wording in brain runbooks |
| 2026-08-04 | Scrub pilot client names and GUIDs from steps; placeholders `{Company}` only |
| 2026-07-14 | Step 08 changed from Global SSO Accept to per-customer SuperOps Client SSO |
| 2026-07-14 | MSP ownership explicit: On IT technicians perform customer-tenant consent and all setup via GDAP |
| 2026-07-14 | Entra ID Free: Sync now auto-assigns active licensed users using the saved Client SSO Application ID |
| 2026-06-25 | Sync now: per-user SCIM provision-on-demand; troubleshooting for plain names when extensionAttribute1 set |
| 2026-06-25 | SuperOps App role step (Value `User`) for Entra ID Free; Application (client) ID on portal |
| 2026-06-24 | In-app checklist manual format; step numbers aligned (05 consent, 06 SCIM, 07 SAML) |
| 2026-06-19 | Auto-maintain group via `GroupMember.ReadWrite.All`; single SuperOps app SCIM+SAML; consent refresh fix documented |
| 2026-06-19 | Full click-by-click runbook for re-do from scratch |
