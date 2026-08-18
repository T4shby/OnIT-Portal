# Access control and user sync

## Two syncs - different scopes

**M365 is the source of truth.** The portal and SuperOps each have their own sync - they do **not** use the same membership rule.

**MSP operating rule:** On IT technicians perform all customer-tenant setup and consent through delegated / GDAP access. Customers receive no onboarding tasks or Accept links.

```
Customer M365 tenant
        │
        ├──► Sync 1: SuperOps SCIM
        │         P1: security group → SuperOps app  →  requesters
        │         Free: app-assigned users (portal assigns via Graph)  →  requesters
        │
        └──► Sync 2: Portal Entra sync
                  Whole tenant (licensed users + shared mailboxes)  →  portal users
```

| Sync | System | Who gets synced | Doc |
|---|---|---|---|
| **1** | SuperOps requesters | Licensed users + shared mailboxes in scope (group members; on Entra ID Free also app-assigned users) | [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| **2** | Portal users | **All licensed M365 users** + **shared mailboxes** in the tenant | [EntraGroupSync.md](EntraGroupSync.md) |

**Do not** run a custom SuperOps API provisioner from the portal. SuperOps handles its own leg via SCIM.

---

## The security group - what it is for

The group `On IT Portal - {Company}` is **not** how the portal discovers users.

| System | Uses the group? |
|---|---|
| **On IT Portal** (`portal:sync-entra-users`) | **Maintains** the group via Graph when `entra_group_id` is set. On **Entra ID Free**, **assigns** licensed users + shared mailboxes to the SuperOps enterprise app when `entra_superops_app_id` is set. **Writes SuperOps last name** to `extensionAttribute1` (does not change M365 `displayName`). **Triggers SCIM provision-on-demand once per user** on Sync now |
| **SuperOps SCIM** | **Yes** - provisions users assigned to the enterprise app (via group on P1, or direct app assignment on Free) |
| **SuperOps Requester Client SSO** | Customer-owned SAML app from checklist 08: **P1** assigns `On IT Portal - {Company}` once; **Free** saves `entra_superops_sso_app_id` and portal Sync assigns active licensed users directly |

### Group membership - automatic

Create an **empty** Assigned security group and paste its Object ID into the portal. Each `portal:sync-entra-users` run:

1. Discovers licensed users + shared mailboxes in the tenant (portal accounts)
2. Adds missing members to the SuperOps SCIM group; removes people who left scope

Requires **GroupMember.ReadWrite.All** (application) with admin consent in the customer tenant. Disable with `ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=false` if you manage the group yourself (e.g. dynamic group on P1).

| Situation | What to do |
|---|---|
| **Entra ID P1** | Empty Assigned group + `entra_group_id` - portal maintains membership; assign group to SuperOps app once in Azure |
| **Entra ID Free** | Same group auto-fill + set SCIM `entra_superops_app_id` and Client SSO `entra_superops_sso_app_id` - portal assigns the required users to both customer-owned enterprise apps |
| **Client already has requesters in SuperOps** | Leave them; SCIM matches by email when they enter the group |
| **Entra ID P1+ dynamic group** | Optional alternative - set `ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=false` so portal does not fight the dynamic rule |
| **One admin only in group before first sync** | On P1, SCIM only sees group members - run **Sync now** so portal fills the group. On Free, portal also assigns the SuperOps app on sync |

**SuperOps Import** is OK for initial CSV load; SCIM handles ongoing changes.

---

## What staff do vs what sync does

| Task | Owner |
|---|---|
| Portal client + SuperOps / Pax8 IDs + sync | On IT technician (portal / SuperOps) |
| Security group, SCIM, Portal Graph consent, SuperOps Client SSO | On IT technician (customer Entra / GDAP) |
| Portal users (licensed + shared mailboxes) | **Automatic** - whole tenant |
| SuperOps requesters | **Automatic** - SCIM for users in app scope (group or direct assignment) |
| SuperOps requester SSO access on Free | **Automatic** - after step 08 Client SSO setup, portal Sync directly assigns active licensed users to `SuperOps Requester SSO - {Company}` |
| SuperOps requester **names** | **Automatic** - portal writes last name to `extensionAttribute1` → SCIM **name.familyName** Direct → `Name (User Mailbox)` or `Name (Shared Mailbox)` in SuperOps |

---

## Short answers

| Question | Answer |
|---|---|
| Does the portal scan all licensed users? | **Yes** - tenant-wide sync when `entra_sync_enabled` + admin consent |
| Do I add everyone to the group for the portal? | **No** - only for SuperOps SCIM / SSO |
| Auto-create portal users? | **Yes** - hourly `portal:sync-entra-users` |
| Auto-create SuperOps requesters? | **Yes** - SCIM for users in app scope |
| What are requesters called in SuperOps? | `Name (User Mailbox)` or `Name (Shared Mailbox)` - portal writes to `extensionAttribute1`; Entra SCIM maps **name.familyName** Direct; M365 `displayName` stays plain |
| Requester still shows plain name (e.g. `Phil Cooper`)? | Check Entra user **extensionAttribute1**, then **Provisioning logs** for **Update**. Run **Sync now** (one provision-on-demand per user) - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| Remove user from group? | SCIM deprovisions SuperOps requester; portal user unchanged unless licence removed |
| Disable M365 account / remove licence? | Portal sync deactivates portal user on next run |
| User **primary email / domain** changes (old becomes alias)? | **Portal:** same Entra object id → **email updated on one row**. **SuperOps:** after each Entra Sync job, API aligns requester email (match SuperOps id → primary → Graph aliases → unique local-part) and binds `superops_user_id`. SCIM alone is not enough. [DomainEmailChange.md](DomainEmailChange.md) |
| In portal + M365 but **missing from SuperOps**; Sync succeeds | Entra **SCIM Sync 1** stopped or app has **0 templates** - Integration Health SuperOps SCIM / step 07 Failed. **0 templates** → Retry Graph setup then Apply SCIM. Else `portal:repair-superops-scim --client={id}` - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |

---

## Offboarding

| Action | Portal | SuperOps |
|---|---|---|
| Remove licence / disable account in M365 | Deactivated on next portal sync | SCIM should deactivate if still in group - prefer remove from group too |
| Remove from security group only | No change (still licensed) | SCIM deprovisions requester |

---

## Portal login flow

```
User → app.onit.ltd → Microsoft OAuth
     → portal checks users table (sync-managed)
     → allowed if active row exists and portal_login_enabled
```

Shared mailboxes sync to the portal for directory/SuperOps records but **cannot** sign in (`portal_login_enabled=false`).

On login, `SuperOpsUserSyncService` may **link** `superops_user_id` by email if API token is set. It does not create requesters - SCIM does.

Login matches **`entra_object_id` first** (stable), then email. On success it may refresh `users.email` to the current Microsoft primary when free.

**Primary / domain email change:** portal rows are keyed by object id on sync - see [DomainEmailChange.md](DomainEmailChange.md).

---

## What is built (portal - Sync 2)

| Piece | Status |
|---|---|
| `entra_tenant_id` on clients (required for sync) | ✅ |
| `entra_group_id` on clients (required for auto SuperOps group maintain) | ✅ |
| `entra_superops_app_id` on clients (Entra ID Free - auto-assign users to SCIM app) | ✅ |
| `entra_superops_sso_app_id` on clients (Entra ID Free - auto-assign users to Client SSO app) | ✅ |
| Auto-assign SuperOps enterprise app users via `AppRoleAssignment.ReadWrite.All` (licensed + shared mailboxes) | ✅ |
| Auto-assign active licensed users to customer SuperOps Client SSO after step 08 (Entra ID Free) | ✅ |
| Entra SCIM provision-on-demand after Sync now (one user per call) via `Synchronization.ReadWrite.All` | ✅ |
| `ENTRA_SYNC_SUPEROPS_NAME_EXTENSION_ATTRIBUTE` (default 1) | ✅ |
| `ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP` (default true) | ✅ |
| Auto-maintain SCIM group via `GroupMember.ReadWrite.All` | ✅ |
| `portal:sync-entra-users` | ✅ Tenant-wide |
| SuperOps requester provisioning | ✅ SuperOps SCIM (Sync 1) |
| `portal:repair-superops-scim` - recreate/start Entra SCIM job when BaseAddress still in Entra | ✅ |
| SuperOps requester **primary email** align after Entra Sync | ✅ API (`ENTRA_SYNC_SUPEROPS_EMAIL_ALIGN`) - [DomainEmailChange.md](DomainEmailChange.md) |
| Portal identity by Entra object id + primary email update | ✅ |

---

## Related docs

| Doc | Topic |
|---|---|
| [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) | **Complete re-do - permissions, group, SCIM, SAML, deploy** |
| [DomainEmailChange.md](DomainEmailChange.md) | Domain / primary email cutover |
| [SuperOpsEntraSync.md](SuperOpsEntraSync.md) | Sync 1 - SCIM per client |
| [EntraGroupSync.md](EntraGroupSync.md) | Sync 2 - portal tenant sync |
| [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) | In-app wizard |

---

## Change log

| Date | Change |
|---|---|
| 2026-08-13 | 0-templates SCIM create bug: template instantiate + Retry Graph recreate; never Azure UI delete for Sync 1 |
| 2026-08-13 | SCIM job missing troubleshooting + repair command; Sync 2 provision-on-demand for users without `superops_user_id` |
| 2026-08-11 | SuperOps email align + Graph aliases + object-id portal upsert on domain change |
| 2026-07-14 | MSP ownership explicit: every customer-tenant setup/consent action is completed by On IT through GDAP |
| 2026-07-14 | Entra ID Free: Sync now auto-assigns all active licensed users to customer SuperOps Requester SSO SP after Accept |
| 2026-06-25 | Sync now: per-user SCIM provision-on-demand; sync UI spinner; docs aligned on name.familyName mapping |
| 2026-06-25 | Full SuperOps SCIM name in `extensionAttribute1`; Direct `name.familyName` mapping; provision-on-demand on Sync now |
| 2026-06-25 | Entra ID Free: `entra_superops_app_id` includes shared mailboxes on app assign |
| 2026-06-19 | Auto-maintain group; single SuperOps app; [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| 2026-06-16 | Clarify portal = whole tenant; group = SuperOps SCIM/SSO only (not portal scope) |
| 2026-06-19 | Two-sync model documented |
