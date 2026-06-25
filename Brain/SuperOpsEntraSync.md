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
5. **Users and groups:**
   - **Entra ID P1:** assign security group `On IT Portal - {Company}` once (portal sync keeps it filled)
   - **Entra ID Free:** copy **Application (client) ID** from App registrations → SuperOps → Overview → portal field; portal sync assigns licensed users automatically
6. Start provisioning

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
| User added to group / app scope | Requester created (or updated) with `displayName` from Entra |
| User removed from group / app scope | Requester deprovisioned |
| Account disabled | Handled per SuperOps SCIM rules |

**Requester name format:** SuperOps SCIM maps a **custom attribute** — not Entra `displayName`. Portal sync writes `User` or `Shared Mailbox` to `extensionAttribute1` (configurable). Entra provisioning expression joins that hint with the real M365 display name so requesters show `Phil Cooper (User)` or `Accounts (Shared Mailbox)` **only in SuperOps**. M365 Active users keep plain names.

Existing requesters (e.g. Ductec already in SuperOps) are matched and updated by SCIM — not duplicated.

---

## Requester display names

**Never patch Entra `displayName`.** SuperOps requester labels use SCIM attribute mapping on the SuperOps enterprise app.

| Identity in M365 | Entra `displayName` | SuperOps requester name (after SCIM) |
|---|---|---|
| Licensed user | `Phil Cooper` (unchanged) | `Phil Cooper (User)` |
| Shared mailbox | `Accounts` (unchanged) | `Accounts (Shared Mailbox)` |

### How it works

```
portal:sync-entra-users
  → PATCH onPremisesExtensionAttributes.extensionAttribute1 = "User" | "Shared Mailbox"
  → user in SuperOps SCIM group + app assignment
Entra provisioning (SCIM, must be ON)
  → displayName expression: Join([displayName], " (", [extensionAttribute1], ")")
  → SuperOps requester created/updated with formatted name
```

### Entra SCIM attribute mapping (one-time per customer)

In **Entra → Enterprise applications → SuperOps - {Company} → Provisioning → Edit attribute mapping → Provision Microsoft Entra ID Users**:

| Attribute | Mapping type | Expression / source |
|---|---|---|
| `displayName` | Expression | `IIF(IsNullOrEmpty([extensionAttribute1]), [displayName], Join([displayName], " (", [extensionAttribute1], ")"))` |
| `extensionAttribute1` | Direct | `extensionAttribute1` (optional — only if you need it in SuperOps) |

Save mapping, then run **Provision on demand** or wait for the next SCIM cycle.

### Graph permissions required (portal OAuth app)

| Permission | Purpose |
|---|---|
| `User.ReadWrite.All` | Set `extensionAttribute1` SuperOps name hint; revert mistaken displayName suffixes |
| `Application.Read.All` | Resolve SuperOps Application (client) ID → enterprise app |
| `AppRoleAssignment.ReadWrite.All` | Assign users to SuperOps enterprise app on Entra ID Free |
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

Set to `0` to stop writing SuperOps name hints (requesters fall back to plain M365 displayName).

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
| Licensed active user | ✅ can sign in | ✅ | ✅ | `(User)` |
| Shared mailbox | ✅ directory only | ✅ | ✅ | `(Shared Mailbox)` |
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
| ☐ | Assign security group to SCIM app **or** set `entra_superops_app_id` on portal (Entra ID Free) |
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
| Requester name is plain (no suffix) | Portal sync not run yet, or `User.ReadWrite.All` missing — run **Sync now**, re-consent, wait for SCIM or **Provision on demand** |
| “Groups are not available for assignment due to your Active Directory plan level” | **Entra ID Free** — set **SuperOps Application (client) ID** on the portal client (App registrations → Overview — not Object ID). Add `Application.Read.All`, `AppRoleAssignment.ReadWrite.All`, re-consent in customer tenant |

---

## Change log

| Date | Change |
|------|--------|
| 2026-06-25 | Requester display names: `(User Mailbox)` / `(Shared Mailbox)` via Entra displayName sync |
| 2026-06-25 | Entra ID Free: portal auto-assigns users via `entra_superops_app_id` + `AppRoleAssignment.ReadWrite.All` |
| 2026-06-25 | Bearer authentication on SCIM admin credentials |
| 2026-06-19 | Auto-maintain group; single app SCIM+SAML default; link to [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| 2026-06-19 | Document two-sync model; SuperOps SCIM per client (Ductec, On IT) |
