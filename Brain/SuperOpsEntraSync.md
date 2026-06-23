# SuperOps Entra SCIM sync (per client)

**Sync 1 of 2** in the three-way model. M365 is the source of truth. SuperOps requesters are provisioned by **SuperOps's native Entra ID integration** (SCIM), not by the portal.

See also: [AccessAndSync.md](AccessAndSync.md) | [EntraGroupSync.md](EntraGroupSync.md) (portal sync)

---

## Architecture

```
M365 security group (per customer tenant)  ←  SOURCE OF TRUTH
        │
        ├──► SuperOps SCIM provisioning     (this doc — Sync 1)
        │
        └──► Portal Entra group sync        (EntraGroupSync.md — Sync 2)
```

**Two independent syncs.** Same group membership in Entra drives both. Do not use portal code to create SuperOps requesters.

---

## Per-client setup (e.g. Ductec)

> **In-app wizard:** **Admin → Clients → Edit** → checklist steps **05 (SCIM)** and **07 (Client SSO)** contain the full click-by-click instructions for technicians. This doc is the reference copy.

### 1. Create the security group in customer Entra

1. Customer tenant (e.g. Ductec) → **Entra ID → Groups**
2. Create security group: `On IT Portal - {Company}`
3. Add all users who need portal + SuperOps access
4. This group is assigned to **both** provisioning apps below

### 2. SuperOps — generate SCIM credentials

1. SuperOps MSP console → **Integrations → Microsoft Entra ID**
2. **Generate Tokens** → select the SuperOps client (e.g. **Ductec LTD**)
3. Copy **Tenant URL** and **Auth Token** (SCIM endpoint)

You already have tokens for **On IT LTD** and **Ductec LTD** in this screen.

### 3. Customer Entra — enterprise app for SCIM provisioning

In the **customer's** Entra tenant (not On IT's):

1. **Entra ID → Enterprise applications → New application**
2. Create a **non-gallery** app (e.g. `SuperOps Provisioning - Ductec`)
3. **Provisioning** → Mode: **Automatic**
4. **Admin Credentials:**
   - **Tenant URL** — paste from SuperOps
   - **Secret Token** — paste Auth Token from SuperOps
5. **Test connection** → Save
6. **Mappings** — ensure user principal name / email maps correctly
7. **Users and groups** — assign the `On IT Portal - Ductec` security group
8. Start provisioning (or wait for sync cycle)

SuperOps will auto-create/update/deactivate requesters based on group membership.

### 4. SuperOps Client SSO (separate — for login)

SCIM provisions **users**. **Client SSO** (SAML) is still required for Microsoft sign-in to `portal.onit.ltd` (SuperOps requester portal). See [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) and in-app setup wizard step 6.

---

## What SCIM handles

| Event in M365 | SuperOps |
|---|---|
| User added to group | Requester created (or updated) |
| User removed from group | Requester deprovisioned |
| Account disabled | Handled per SuperOps SCIM rules |

Existing requesters (e.g. Ductec already in SuperOps) are matched and updated by SCIM — not duplicated.

---

## What SCIM does not handle

| System | Handled by |
|---|---|
| **On IT Portal** users | Portal sync — [EntraGroupSync.md](EntraGroupSync.md) |
| **Portal login** (OAuth) | On IT Portal app — separate from SCIM app |
| **SuperOps SSO login** (SAML) | Client SSO enterprise app — separate from SCIM app |

You may have **three** Entra enterprise apps per customer tenant:

| App | Purpose |
|---|---|
| SuperOps SCIM provisioning | M365 → SuperOps requesters |
| SuperOps Client SSO (SAML) | Microsoft login to SuperOps |
| On IT Portal (via multi-tenant OAuth + group sync) | Microsoft login to `app.onit.ltd` |

---

## Checklist per customer

| Done | Task |
|---|---|
| ☐ | Security group `On IT Portal - {Company}` in customer Entra |
| ☐ | SuperOps → Generate Tokens for this client |
| ☐ | Entra enterprise app → SCIM provisioning → Test connection |
| ☐ | Assign security group to SCIM app |
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
| SCIM test connection fails | Tenant URL and token from correct SuperOps client row |

---

## Change log

| Date | Change |
|---|---|
| 2026-06-19 | Document two-sync model; SuperOps SCIM per client (Ductec, On IT) |
