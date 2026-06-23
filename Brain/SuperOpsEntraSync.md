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

> **In-app wizard:** **Admin → Clients → Edit** → checklist steps **05 (SCIM)** and **07 (Client SSO)** contain the full click-by-click instructions for technicians. This doc is the reference copy.

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
3. **Provisioning** → Automatic → Tenant URL + Secret Token from SuperOps → **Test connection** → Save
4. **Users and groups** → assign `On IT Portal - {Company}` (empty group — portal sync fills it)
5. Start provisioning

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
