# Access control and user sync

## Two syncs — different scopes

**M365 is the source of truth.** The portal and SuperOps each have their own sync — they do **not** use the same membership rule.

```
Customer M365 tenant
        │
        ├──► Sync 1: SuperOps SCIM
        │         Security group members only  →  SuperOps requesters
        │
        └──► Sync 2: Portal Entra sync
                  Whole tenant (licensed users + shared mailboxes)  →  portal users
```

| Sync | System | Who gets synced | Doc |
|---|---|---|---|
| **1** | SuperOps requesters | Users in security group `On IT Portal - {Company}` | [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| **2** | Portal users | **All licensed M365 users** + **shared mailboxes** in the tenant | [EntraGroupSync.md](EntraGroupSync.md) |

**Do not** run a custom SuperOps API provisioner from the portal. SuperOps handles its own leg via SCIM.

---

## The security group — what it is for

The group `On IT Portal - {Company}` is **not** how the portal discovers users.

| System | Uses the group? |
|---|---|
| **On IT Portal** (`portal:sync-entra-users`) | **Maintains** the group via Graph when `entra_group_id` is set. On **Entra ID Free**, also **assigns users** to the SuperOps enterprise app when `entra_superops_app_id` is set |
| **SuperOps SCIM** | **Yes** — only group members are provisioned as requesters |
| **SuperOps Client SSO (SAML)** | **Yes** — assign the same group to the SAML app |

### Group membership — automatic

Create an **empty** Assigned security group and paste its Object ID into the portal. Each `portal:sync-entra-users` run:

1. Discovers licensed users + shared mailboxes in the tenant (portal accounts)
2. Adds missing members to the SuperOps SCIM group; removes people who left scope

Requires **GroupMember.ReadWrite.All** (application) with admin consent in the customer tenant. Disable with `ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=false` if you manage the group yourself (e.g. dynamic group on P1).

| Situation | What to do |
|---|---|
| **Entra ID P1** | Empty Assigned group + `entra_group_id` — portal maintains membership; assign group to SuperOps app once in Azure |
| **Entra ID Free** | Same group auto-fill + set `entra_superops_app_id` — portal assigns licensed users to SuperOps app (no manual Azure assignment) |
| **Client already has requesters in SuperOps** | Leave them; SCIM matches by email when they enter the group |
| **Entra ID P1+ dynamic group** | Optional alternative — set `ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=false` so portal does not fight the dynamic rule |
| **One admin only in group** | ❌ Does not sync other staff — group must contain (or auto-include) everyone SCIM should provision |

**SuperOps Import** is OK for initial CSV load; SCIM handles ongoing changes.

---

## What staff do vs what sync does

| Task | Owner |
|---|---|
| Portal client + SuperOps / Pax8 IDs + sync | On IT technician (portal / SuperOps) |
| Security group, SCIM, consent, Client SSO | On IT technician (customer Entra / GDAP) |
| Portal users (licensed + shared mailboxes) | **Automatic** — whole tenant |
| SuperOps requesters | **Automatic** — SCIM for group members only |

---

## Short answers

| Question | Answer |
|---|---|
| Does the portal scan all licensed users? | **Yes** — tenant-wide sync when `entra_sync_enabled` + admin consent |
| Do I add everyone to the group for the portal? | **No** — only for SuperOps SCIM / SSO |
| Auto-create portal users? | **Yes** — hourly `portal:sync-entra-users` |
| Auto-create SuperOps requesters? | **Yes** — SCIM, but only for **group members** |
| Remove user from group? | SCIM deprovisions SuperOps requester; portal user unchanged unless licence removed |
| Disable M365 account / remove licence? | Portal sync deactivates portal user on next run |

---

## Offboarding

| Action | Portal | SuperOps |
|---|---|---|
| Remove licence / disable account in M365 | Deactivated on next portal sync | SCIM should deactivate if still in group — prefer remove from group too |
| Remove from security group only | No change (still licensed) | SCIM deprovisions requester |

---

## Portal login flow

```
User → app.onit.ltd → Microsoft OAuth
     → portal checks users table (sync-managed)
     → allowed if active row exists and portal_login_enabled
```

Shared mailboxes sync to the portal for directory/SuperOps records but **cannot** sign in (`portal_login_enabled=false`).

On login, `SuperOpsUserSyncService` may **link** `superops_user_id` by email if API token is set. It does not create requesters — SCIM does.

---

## What is built (portal — Sync 2)

| Piece | Status |
|---|---|
| `entra_tenant_id` on clients (required for sync) | ✅ |
| `entra_group_id` on clients (required for auto SuperOps group maintain) | ✅ |
| `entra_superops_app_id` on clients (Entra ID Free — auto-assign users to SCIM app) | ✅ |
| Auto-assign SuperOps enterprise app users via `AppRoleAssignment.ReadWrite.All` | ✅ |
| `ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP` (default true) | ✅ |
| Auto-maintain SCIM group via `GroupMember.ReadWrite.All` | ✅ |
| `portal:sync-entra-users` | ✅ Tenant-wide |
| SuperOps requester provisioning | ✅ SuperOps SCIM (Sync 1) |

---

## Related docs

| Doc | Topic |
|---|---|
| [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) | **Complete re-do — permissions, group, SCIM, SAML, deploy** |
| [SuperOpsEntraSync.md](SuperOpsEntraSync.md) | Sync 1 — SCIM per client |
| [EntraGroupSync.md](EntraGroupSync.md) | Sync 2 — portal tenant sync |
| [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) | In-app wizard |

---

## Change log

| Date | Change |
|---|---|
| 2026-06-25 | Entra ID Free workaround: portal assigns users to SuperOps enterprise app via `entra_superops_app_id` |
| 2026-06-19 | Auto-maintain group; single SuperOps app; [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| 2026-06-16 | Clarify portal = whole tenant; group = SuperOps SCIM/SSO only (not portal scope) |
| 2026-06-19 | Two-sync model documented |
