# SuperOps Entra SCIM sync (per client)

**Sync 1 of 2** in the three-way model. M365 is the source of truth. SuperOps requesters are provisioned by **SuperOps's native Entra ID integration** (SCIM), not by the portal.

See also: [AccessAndSync.md](AccessAndSync.md) | [EntraGroupSync.md](EntraGroupSync.md) (portal sync)

---

## Architecture

```
Customer M365 tenant
        │
        ├──► SuperOps SCIM
        │         P1: group assigned to app once → requesters follow group membership
        │         Free: users assigned to app by portal sync → requesters follow app users
        │
        └──► Portal Entra sync      whole tenant (licensed + shared mailboxes)  →  portal users
                  also maintains security group membership when entra_group_id is set
```

**Portal does not read the group for user discovery.** The group is **maintained** by portal sync and controls **SuperOps SCIM on P1** (assign group to app once). On **Free**, SCIM scope comes from **SuperOps app users** assigned by the portal.

---

## Per-client setup (e.g. Ductec)

> **In-app wizard:** **Admin → Clients → Edit** → checklist steps **05 (SCIM)** and **06 (Global SSO Accept)** contain the full install-manual instructions. This doc is the reference copy.

### 1. Create the security group in customer Entra

Used for **SuperOps SCIM** (and Portal group visibility) — portal sync **maintains membership** when `entra_group_id` is saved on the client. Requester **login** is Global SSO + checklist **06** Accept — not SAML on this customer SCIM app.

1. Customer tenant (e.g. Ductec) → **Entra ID → Manage → Groups**
2. Create security group: `On IT Portal - {Company}` — type **Security**, membership **Assigned**
3. **Leave the group empty** — `portal:sync-entra-users` adds licensed users and shared mailboxes via Microsoft Graph (same scope as portal users)
4. Copy **Object ID** → **Entra group ID** on the portal client record
5. **Entra ID P1 only:** assign this group to the SuperOps Entra **SCIM** app once. **Entra ID Free:** do **not** assign the group in Azure — paste **SuperOps Application (client) ID** on the portal (checklist step 07).

### 1b — Legacy / optional alternatives

You no longer need PowerShell bulk-add or dynamic groups for most clients. The portal writes group membership on each sync.

| Situation | What to do |
|---|---|
| **Default** | Empty Assigned group + `entra_group_id` — automatic |
| **Client already has requesters in SuperOps** | Leave them. SCIM **matches by email** — no duplicates. |
| **Entra ID P1+ dynamic group** | Set `ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=false` on the server if you prefer a dynamic rule instead |
| **Greenfield, few users** | Still use an empty group — first sync adds everyone |

**Does not work:** putting only one admin in the group and expecting SCIM to provision everyone else. SCIM syncs **group members only**.

**SuperOps Import** (Clients → Requesters → Import) is fine for a **one-time** CSV load; SCIM + automatic group membership handles **ongoing** joiners/leavers.

### 2–3. One Entra app: SCIM only

**Default per customer:** one non-gallery enterprise app (e.g. `SuperOps - Ductec LTD`) with **Provisioning** and **SAML** on the same object. Assign the security group **once**.

#### 2a. SuperOps — SCIM credentials

1. SuperOps MSP console → **Integrations → Microsoft Entra ID**
2. **Generate Tokens** → select the SuperOps client (e.g. **Ductec LTD**)
3. Copy **Tenant URL** and **Auth Token**

#### 2b. Customer Entra — create app and SCIM

1. **Entra ID → Enterprise applications → New application**
2. Non-gallery: `SuperOps - {Company}` → Create
3. **Provisioning** → Automatic
4. **Admin credentials:** Authentication method = **Bearer authentication** (default — do not change). **Tenant URL** = from SuperOps. **Secret token** = SuperOps Auth Token → **Test connection** → Save
5. **App role (Entra ID Free — required for portal sync to assign users):** **App registrations** → SuperOps app → **App roles** → **Create app role** if none exists: Display name `User`, Users/Groups, **Value** `User`, **Description** `Default access for SCIM users`, Enable → Save. Skip if an enabled User role already exists.
6. **Users and groups:**
   - **Entra ID P1:** assign security group `On IT Portal - {Company}` once (portal sync keeps it filled)
   - **Entra ID Free:** copy **Application (client) ID** from App registrations → SuperOps → Overview → portal field; portal sync assigns licensed users automatically
7. Start provisioning

#### 2c. Requester login — Global SSO + per-tenant Accept (not on the customer SCIM app)

On IT uses **Global SSO** ([SuperOps article](https://support.superops.com/en/articles/11583025-setting-up-requester-sso-in-superops)) plus **customer Global Admin Accept** of the multitenant app:

| Field | Where it comes from |
|-------|---------------------|
| **Identifier (Entity ID)** | Type **`https://clientuser.superops.ai`** — fixed SuperOps value for Global SSO. Not per-client. |
| **Reply URL** | SuperOps → Settings → Requester Login → SSO Protected → **Global SSO** → **Consumer Service URL** |
| **Per client** | Checklist **08** Accept URL → customer GA Accepts `bf1c303e-6015-43f7-abb2-5dfe8f67a5a1` → assign `On IT Portal - {Company}` in **customer** tenant |

Configure SAML once on the **On IT** Entra app (**SuperOps Requester SSO (On IT)**), then paste Login URL + certificate into SuperOps **Global SSO**. Do **not** put Global SSO SAML on the customer `SuperOps - {Company}` SCIM app. Do **not** use SuperOps Client SSO.

Full detail: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md). Checklist step **08** is the Accept — not “tick because platform exists”.

### Legacy: two separate Entra apps

Only if the single-app setup fails validation: `SuperOps Provisioning - {Company}` + `SuperOps SSO - {Company}`, both assigned the same group.

---

## What SCIM handles

| Event in M365 | SuperOps |
|---|---|
| User added to group / app scope | Requester created (or updated) with **name.familyName** from `extensionAttribute1` |
| User removed from group / app scope | Requester deprovisioned |
| Account disabled | Handled per SuperOps SCIM rules |

**Requester name format:** Portal writes the full **Last name** to `extensionAttribute1` (e.g. `Munns (User Mailbox)`). Entra SCIM maps it **Direct** to `name.familyName`. First name stays plain via `givenName`.

Existing requesters (e.g. Ductec already in SuperOps) are matched and updated by SCIM — not duplicated.

---

## Requester display names

**Never patch Entra `displayName`.** Portal builds the full **Last name**; Entra passes it through with **Direct** mappings only.

### Where the suffix is added

| Step | System | What happens |
|---|---|---|
| 1 | **Portal** | `formatSuperOpsFamilyName()` → `Munns (User Mailbox)` or `Accounts (Shared Mailbox)` |
| 2 | **Portal** (Graph) | Writes that string to `extensionAttribute1` |
| 3 | **Entra SCIM** | `name.familyName` **Direct** ← `extensionAttribute1` (fallback `[surname]`) |
| 4 | **SuperOps** | First `Hannah`, Last `Munns (User Mailbox)` |

### Entra SCIM attribute mapping — Direct only (no Expression)

| Target | Mapping type | Source | Default if null |
|---|---|---|---|
| `name.givenName` | Direct | `givenName` | — |
| `name.familyName` | Direct | `extensionAttribute1` | `[surname]` |
| `name.formatted` | Direct | `displayName` | — |

**Remove** any Expression on `name.familyName` — portal already sends the full last name.

**Save** → **Sync now** (`SuperOps last names updated N; SuperOps SCIM provision requested for N user(s)`). Portal triggers Entra **provision on demand once per user** (same as the Entra UI) after a short delay so `extensionAttribute1` replicates — check **Provisioning logs** for each Update. While sync runs, the button shows **Syncing…** with a spinner; large tenants may take several minutes.

### Graph permissions required (portal OAuth app)

| Permission | Purpose |
|---|---|
| `User.ReadWrite.All` | Write SuperOps last name (e.g. `Munns (User Mailbox)`) to `extensionAttribute1` |
| `Application.Read.All` | Resolve SuperOps Application (client) ID → enterprise app |
| `AppRoleAssignment.ReadWrite.All` | Assign users to SuperOps enterprise app on Entra ID Free |
| `Synchronization.ReadWrite.All` | Trigger SCIM provision-on-demand after portal Sync now |
| `GroupMember.ReadWrite.All` | Auto-fill SuperOps SCIM security group |

Re-consent in **each customer tenant** after adding permissions.

### Portal client fields

| Field | Purpose |
|---|---|
| `entra_group_id` | Security group Object ID — portal auto-fills members |
| `entra_superops_app_id` | SuperOps **Application (client) ID** from App registrations → Overview — **not** the Object ID on that page. Required on **Entra ID Free**. |

### Server `.env`

```env
ENTRA_SYNC_SUPEROPS_NAME_EXTENSION_ATTRIBUTE=1   # default — extensionAttribute1
ENTRA_SYNC_SUPEROPS_PROVISION_ON_DEMAND=true     # default — trigger SCIM after sync
ENTRA_SYNC_SUPEROPS_PROVISION_DELAY_SECONDS=3  # wait after writing extensionAttribute1 (Entra replication)
ENTRA_SYNC_SUPEROPS_PROVISION_BATCH_SIZE=1       # one user per provision-on-demand call (matches Entra UI)
ENTRA_SYNC_SUPEROPS_PROVISION_INTERVAL_US=1500000  # 1.5s between calls (~35s for 22 users)
```

Set `ENTRA_SYNC_SUPEROPS_NAME_EXTENSION_ATTRIBUTE=0` to stop writing SuperOps name labels to `extensionAttribute1` (last names stay plain in SuperOps).

### Fix mistaken M365 display names (e.g. Ductec)

If a previous sync incorrectly appended `(User Mailbox)` to Entra `displayName`:

```bash
php artisan portal:revert-entra-display-names --client={id} --dry-run
php artisan portal:revert-entra-display-names --client={id}
```

Then configure SCIM attribute mapping above and run **Sync now** so extension attributes are set.

### Who is in scope

| Type | Portal user | SuperOps group | SuperOps app (Free) | SuperOps requester name |
|---|---|---|---|---|
| Licensed active user | ✅ can sign in | ✅ | ✅ | Last name `Munns (User Mailbox)` |
| Shared mailbox | ✅ directory only | ✅ | ✅ | Last name `Accounts (Shared Mailbox)` |
| Disabled licensed user | ❌ inactive | ❌ removed from group | ❌ removed from app | SCIM deprovisions |

---

## What SCIM does not handle

| System | Handled by |
|---|---|
| **On IT Portal** users | Portal sync — [EntraGroupSync.md](EntraGroupSync.md) |
| **Portal login** (OAuth) | On IT Portal app — separate from SCIM app |
| **SuperOps SSO login** (SAML) | On IT **Global SSO** + customer Accept of **SuperOps Requester SSO (On IT)** — not the SCIM app |

You may have **two** Entra enterprise apps in the customer tenant (plus the consented SuperOps Requester SSO SP after step **08**):

| App | Purpose |
|---|---|
| **SuperOps - {Company}** | SCIM provisioning only |
| **SuperOps Requester SSO (On IT)** | Appears after checklist **08** Accept — assign Portal group |
| On IT Portal (multi-tenant OAuth + Graph sync) | Microsoft login to `app.onit.ltd` — consented in customer tenant (step 04) |

Legacy fallback: separate SCIM apps if single-app SCIM setup fails — still do **not** use SuperOps Client SSO for login.

---

## Checklist per customer

| Done | Task |
|---|---|
| ☐ | Security group `On IT Portal - {Company}` in customer Entra |
| ☐ | SuperOps → Generate Tokens for this client |
| ☐ | Entra enterprise app → SCIM provisioning → Test connection |
| ☐ | App registrations → SuperOps app → **App roles** → User role (Value `User`) — **Entra ID Free** |
| ☐ | Provisioning → **name.givenName** Direct; **name.familyName** Direct from extensionAttribute1 |
| ☐ | Assign security group to SCIM app **or** SuperOps Application (client) ID on portal (Entra ID Free) |
| ☐ | Checklist **08** — customer GA Accepted SuperOps Requester SSO; Portal group assigned |
| ☐ | Portal client record + Entra sync enabled — [EntraGroupSync.md](EntraGroupSync.md) |
| ☐ | Test: add user to group → appears in SuperOps + portal after sync |

---

## Troubleshooting

| Problem | Check |
|---|---|
| Requester not in SuperOps | SCIM app group assignment; provisioning logs in Entra |
| Requester not in portal | Portal sync (`portal:sync-entra-users`); client Entra fields |
| Duplicate requesters | Only one SCIM app per SuperOps client; do not also API-provision |
| SCIM test connection fails | Tenant URL and token from correct SuperOps client row; auth method must be **Bearer authentication** |
| Provisioning is **Off** | Turn **ON** under Provisioning — SCIM does not run while off |
| Requester last name plain | `name.familyName` not Direct from extensionAttribute1 | Direct map; Sync now |
| `extensionAttribute1` set but SuperOps name still plain | Entra **Provisioning logs** — if user has no **Update** entry, SCIM did not run for them. Portal **Sync now** triggers provision-on-demand **one user per call** (same as Entra UI). Manual test: **Provision on demand** → pick user → confirm `name.familyName` exports. |
| Sync banner says provision requested for N but logs show fewer Updates | Normal until Entra finishes — wait 1–2 min and refresh logs; re-run **Sync now** if needed |
| “Groups are not available for assignment due to your Active Directory plan level” | **Entra ID Free** — SuperOps Application (client) ID on portal + App role + re-consent |
| `Permission being assigned was not found` | App role missing or **Value** blank | App registrations → App roles → User, Value `User`, Enable |

---

## Change log

| Date | Change |
|------|--------|
| 2026-07-14 | Checklist SSO Accept is step **08** (stale “06” refs corrected); live guide uses multi-block Where paths |
| 2026-06-25 | Sync now: provision-on-demand **one user per API call** + delay after `extensionAttribute1`; spinner/status banner on Edit client |
| 2026-06-25 | Portal writes full SCIM name to `extensionAttribute1`; Direct `name.familyName` mapping; provision-on-demand on Sync now |
| 2026-06-25 | Requester display names: `(User Mailbox)` / `(Shared Mailbox)` — not via Entra `displayName` |
| 2026-06-25 | Entra ID Free: portal auto-assigns users via `entra_superops_app_id` + `AppRoleAssignment.ReadWrite.All` |
| 2026-06-25 | Bearer authentication on SCIM admin credentials |
| 2026-06-19 | Auto-maintain group; single app SCIM+SAML default; link to [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| 2026-06-19 | Document two-sync model; SuperOps SCIM per client (Ductec, On IT) |
