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
| **On IT Portal** (`portal:sync-entra-users`) | **No** — reads the whole tenant via Graph. `entra_group_id` on the client record is optional (reference / SCIM). |
| **SuperOps SCIM** | **Yes** — only group members are provisioned as requesters |
| **SuperOps Client SSO (SAML)** | **Yes** — assign the same group to the SAML app |

### Adding members to the group

- **Entra ID Free** (e.g. many small tenants): membership type **Assigned** — add users manually when they need SuperOps (`Members → Add`).
- **Entra ID P1+** (optional): use a **Dynamic user** group with a rule (e.g. licensed users) to avoid manual adds.

New licensed user in M365 → appears in **portal** on next hourly sync automatically. They only appear in **SuperOps** after they are in the security group (manual add or dynamic rule).

---

## What staff do vs what sync does

| Task | Who |
|---|---|
| Create security group + add SuperOps users | M365 admin |
| SuperOps SCIM app + group assignment | M365 admin |
| SuperOps Client SSO (SAML) | M365 admin |
| Portal client record + Entra tenant ID + sync enabled | Technician |
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
| `entra_group_id` optional (SCIM reference, not portal scope) | ✅ |
| `portal:sync-entra-users` | ✅ Tenant-wide |
| SuperOps requester provisioning | ✅ SuperOps SCIM (Sync 1) |

---

## Related docs

| Doc | Topic |
|---|---|
| [SuperOpsEntraSync.md](SuperOpsEntraSync.md) | Sync 1 — SCIM per client |
| [EntraGroupSync.md](EntraGroupSync.md) | Sync 2 — portal tenant sync |
| [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) | In-app wizard |

---

## Change log

| Date | Change |
|---|---|
| 2026-06-16 | Clarify portal = whole tenant; group = SuperOps SCIM/SSO only (not portal scope) |
| 2026-06-19 | Two-sync model documented |
