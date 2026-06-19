# Access control and user sync

## Three-way sync — two independent syncs

**M365 security group = source of truth** (one group per customer tenant, e.g. `On IT Portal - Ductec`).

```
M365 security group
        │
        ├──► Sync 1: SuperOps Entra SCIM     →  requesters in SuperOps
        │
        └──► Sync 2: Portal Entra group sync  →  users in app.onit.ltd
```

| Sync | System | How | Doc |
|---|---|---|---|
| **1** | SuperOps requesters | SuperOps **Integrations → Entra ID** → SCIM per client | [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| **2** | Portal users | `portal:sync-entra-users` (hourly) | [EntraGroupSync.md](EntraGroupSync.md) |

**Do not** run a custom SuperOps API provisioner from the portal. SuperOps handles its own leg.

---

## What staff do vs what sync does

| Task | Who |
|---|---|
| Create security group + add users | Tom (M365 admin) |
| SuperOps SCIM app + group assignment | Tom (per customer tenant) |
| SuperOps Client SSO (SAML) | Tom (once per customer) |
| Portal client record + Entra group ID | Technician |
| Portal users | **Automatic** (Sync 2) |
| SuperOps requesters | **Automatic** (Sync 1) |

---

## Short answers

| Question | Answer |
|---|---|
| Source of truth? | **M365 security group** per customer |
| Auto-create portal users? | **Yes** — `ENTRA_SYNC_ENABLED=true` + group on client |
| Auto-create SuperOps requesters? | **Yes** — SuperOps SCIM provisioning per client |
| Remove user from group? | SCIM deprovisions SuperOps; portal sync deactivates portal user |
| Disable M365 account? | Microsoft blocks login; both syncs should reflect disabled state |

---

## Offboarding

**Single action in M365:** remove from security group (or disable account).

| System | Result |
|---|---|
| M365 | User blocked / removed from group |
| SuperOps | SCIM deprovisions requester |
| Portal | Sync sets `is_active=false` |

Also remove Client SSO app assignment if used separately from the SCIM group.

---

## Portal login flow

```
User → app.onit.ltd → Microsoft OAuth
     → portal checks users table (sync-managed)
     → allowed if active row exists
```

On login, `SuperOpsUserSyncService` may **link** `superops_user_id` by email if API token is set (for embedded `/support` only). It does not create requesters — SCIM does.

---

## What is built (portal — Sync 2 only)

| Piece | Status |
|---|---|
| `entra_tenant_id` + `entra_group_id` on clients | ✅ |
| `portal:sync-entra-users` | ✅ Portal users only |
| Admin dry-run / sync now | ✅ |
| In-app client setup wizard | ✅ Admin → Clients → Edit |
| SuperOps requester provisioning | ✅ **SuperOps SCIM** (not portal code) |

---

## Related documents

| Doc | Purpose |
|---|---|
| [SuperOpsEntraSync.md](SuperOpsEntraSync.md) | Sync 1 — SCIM setup per client |
| [EntraGroupSync.md](EntraGroupSync.md) | Sync 2 — portal setup |
| [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) | In-app wizard + backup checklist |
| [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) | SAML / Client SSO |

---

## Change log

| Date | Change |
|---|---|
| 2026-06-19 | Two-sync model: SuperOps SCIM + portal group sync; removed portal SuperOps API provisioner |
| 2026-06-19 | In-app client setup wizard; Brain docs point to Admin → Clients → Edit |
| 2026-06-16 | Initial doc |
