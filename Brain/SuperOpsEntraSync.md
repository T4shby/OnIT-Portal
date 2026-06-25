# SuperOps Entra SCIM sync (per client)

**Sync 1 of 2** in the three-way model. M365 is the source of truth. SuperOps requesters are provisioned by **SuperOps's native Entra ID integration** (SCIM), not by the portal.

See also: [AccessAndSync.md](AccessAndSync.md) | [EntraGroupSync.md](EntraGroupSync.md) (portal sync)

---

## Architecture

```
Customer M365 tenant
        │
        ├──► SuperOps SCIM          security group members  →  SuperOps requesters
        │
        └──► Portal Entra sync      whole tenant (licensed + shared mailboxes)  →  portal users
```

**Portal does not use the security group.** The group controls **SuperOps SCIM** and **Client SSO** assignment only.

---

## Per-client setup (e.g. Ductec)

> **In-app wizard:** **Admin → Clients → Edit** → checklist steps **05 (SCIM)** and **06 (Client SSO)** contain the full install-manual instructions (prerequisites, numbered parts, verification). This doc is the reference copy.

### 1. Create the security group in customer Entra

Used for **SuperOps SCIM and Client SSO** — portal sync **maintains membership** when `entra_group_id` is saved on the client.

1. Customer tenant (e.g. Ductec) → **Entra ID → Manage → Groups**
2. Create security group: `On IT Portal - {Company}` — type **Security**, membership **Assigned**
3. **Leave the group empty** — `portal:sync-entra-users` adds licensed users and shared mailboxes via Microsoft Graph (same scope as portal users)
4. Copy **Object ID** → **Entra group ID** on the portal client record
5. Assign this group to the SuperOps Entra app once (SCIM + SAML on the same app — see below)

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

### 2–3. One Entra app: SCIM + SAML

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

#### 2c. Same app — Client SSO (SAML)

1. SuperOps → **Settings → Requester Login → Client SSO** → copy Entity ID + Reply URL
2. On the **same** Entra app → **Single sign-on → SAML** → configure Identifier + Reply URL, claims, certificate
3. Paste Entra Login URL + certificate back into SuperOps Client SSO
4. No second app; no second group assignment

If SCIM test connection fails after SAML is added, or SuperOps support confirms incompatibility, fall back to two apps (legacy) — uncommon.

### Legacy: two separate Entra apps

Only if the single-app setup fails validation: `SuperOps Provisioning - {Company}` + `SuperOps SSO - {Company}`, both assigned the same group.

---

## What SCIM handles

| Event in M365 | SuperOps |
|---|---|
| User added to group / app scope | Requester created (or updated) with `name.formatted` from `extensionAttribute1` |
| User removed from group / app scope | Requester deprovisioned |
| Account disabled | Handled per SuperOps SCIM rules |

**Requester name format:** Portal writes `User Mailbox` or `Shared Mailbox` to `extensionAttribute1`. Entra SCIM appends that to **Last name** (`name.familyName`). **M365 `displayName` is never changed.** SuperOps shows e.g. First `Hannah`, Last `Munns (User Mailbox)`.

Existing requesters (e.g. Ductec already in SuperOps) are matched and updated by SCIM — not duplicated.

---

## Requester display names

**Never patch Entra `displayName`.** SuperOps **Last name** gets the suffix via SCIM `name.familyName` Expression.

### Where the suffix is added

| Step | System | What happens |
|---|---|---|
| 1 | **Portal** | Writes `User Mailbox` or `Shared Mailbox` to `extensionAttribute1` |
| 2 | **Entra SCIM** | `name.givenName` ← `givenName`. `name.familyName` ← `surname` + ` (extensionAttribute1)` |
| 3 | **SuperOps** | First `Hannah`, Last `Munns (User Mailbox)` |

| Identity in M365 | SuperOps First / Last |
|---|---|
| Licensed user | `Hannah` / `Munns (User Mailbox)` |
| Shared mailbox (no surname) | `Accounts` / `Accounts (Shared Mailbox)` |

### How it works

```
portal:sync-entra-users → extensionAttribute1 = "User Mailbox" | "Shared Mailbox"
Entra SCIM → name.familyName Expression appends label to surname
SuperOps → Last name field shows suffix
```

### Entra SCIM attribute mapping (one-time per customer)

**Where:** Entra → **OnIT X Superops** → **Provisioning** → **Edit attribute mapping** → **Provision Microsoft Entra ID Users**

**`name.givenName`:**

| Field | Value |
|---|---|
| Mapping type | **Direct** |
| Source | `givenName` |
| Target | `name.givenName` |
| Apply | Always |

**`name.familyName`:**

| Field | Value |
|---|---|
| Mapping type | **Expression** |
| Expression | `IIF(IsNullOrEmpty([extensionAttribute1]), [surname], IIF(IsNullOrEmpty([surname]), Join([givenName], " (", [extensionAttribute1], ")"), Join([surname], " (", [extensionAttribute1], ")")))` |
| Target | `name.familyName` |
| Apply | Always |

**Remove** wrong `displayName` / `name.formatted` Join expressions (cause garbled names).

**Save** → **Sync now** on portal (`SuperOps name labels updated N; SuperOps SCIM provisioned N`).

### Graph permissions required (portal OAuth app)

| Permission | Purpose |
|---|---|
| `User.ReadWrite.All` | Write `User Mailbox` / `Shared Mailbox` to `extensionAttribute1`; revert mistaken displayName suffixes |
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
```

Set to `0` to stop writing SuperOps name labels to `extensionAttribute1` (last names stay plain in SuperOps).

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
| **SuperOps SSO login** (SAML) | Same **SuperOps - {Company}** app as SCIM (default) — see [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |

You may have **two** Entra enterprise apps per customer tenant (plus On IT Portal OAuth in On IT's tenant):

| App | Purpose |
|---|---|
| **SuperOps - {Company}** (single app) | SCIM provisioning + Client SSO (SAML) — **default** |
| On IT Portal (multi-tenant OAuth + Graph sync) | Microsoft login to `app.onit.ltd` — consented in customer tenant |

Legacy fallback: separate SCIM and SSO apps if single-app setup fails.

---

## Checklist per customer

| Done | Task |
|---|---|
| ☐ | Security group `On IT Portal - {Company}` in customer Entra |
| ☐ | SuperOps → Generate Tokens for this client |
| ☐ | Entra enterprise app → SCIM provisioning → Test connection |
| ☐ | App registrations → SuperOps app → **App roles** → User role (Value `User`) — **Entra ID Free** |
| ☐ | Provisioning → **name.givenName** Direct; **name.familyName** Expression + extensionAttribute1 |
| ☐ | Assign security group to SCIM app **or** SuperOps Application (client) ID on portal (Entra ID Free) |
| ☐ | SuperOps Client SSO configured (SAML) |
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
| Requester last name plain (no suffix) | `name.familyName` Expression missing | Map familyName Expression; Sync now |
| Garbled name e.g. `(AccountsShared MailboxAccounts)` | Wrong Join on displayName/name.formatted | Remove; use **name.familyName** Expression only |
| “Groups are not available for assignment due to your Active Directory plan level” | **Entra ID Free** — SuperOps Application (client) ID on portal + App role + re-consent |
| `Permission being assigned was not found` | App role missing or **Value** blank | App registrations → App roles → User, Value `User`, Enable |

---

## Change log

| Date | Change |
|------|--------|
| 2026-06-25 | Portal writes full SCIM name to `extensionAttribute1`; Direct `name.formatted` mapping; provision-on-demand on Sync now |
| 2026-06-25 | Requester display names: `(User Mailbox)` / `(Shared Mailbox)` — not via Entra `displayName` |
| 2026-06-25 | Entra ID Free: portal auto-assigns users via `entra_superops_app_id` + `AppRoleAssignment.ReadWrite.All` |
| 2026-06-25 | Bearer authentication on SCIM admin credentials |
| 2026-06-19 | Auto-maintain group; single app SCIM+SAML default; link to [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| 2026-06-19 | Document two-sync model; SuperOps SCIM per client (Ductec, On IT) |
