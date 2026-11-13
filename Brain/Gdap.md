# GDAP lifecycle (partner admin relationship)

On IT technicians sign into a customer tenant with **granular delegated admin privileges (GDAP)**. That is a Partner Center admin relationship between the **On IT partner tenant** and the customer tenant.

Connect Microsoft (checklist steps 03 and 04) is a different grant. It lets the portal app call Graph in the customer tenant. A client can be fully onboarded and still have no GDAP. This feature closes that gap.

Steps **04** (Portal Graph Accept) and **08** (SuperOps Client SSO) stay on those numbers. GDAP is a strip above the setup guide, not a new numbered step.

The code default is **off**. Production was turned on 2026-11-13 (`552d51f`) so the strip can be tested. The partner tenant is the directory that publishes `onit.ltd`. `GDAP_TECHNICIAN_GROUP_ID` is still empty, so the audit cannot assign technicians. Connect is gated once the partner app is configured and the snapshot is not ready. See [one-time setup](#one-time-setup-in-the-on-it-tenant).

## What Microsoft allows

Called as the On IT partner tenant (client credentials), Graph v1.0 `tenantRelationships`:

| Step | API | Who |
|---|---|---|
| List relationships | `GET /tenantRelationships/delegatedAdminRelationships` | Portal, nightly and on Check GDAP now |
| Create a request (max 2 years) | `POST /tenantRelationships/delegatedAdminRelationships` | Portal, when none is usable |
| Send it for approval | `POST .../requests` with `action: lockForApproval` | Portal, immediately after create |
| Approve | Customer tenant Global Admin opens the approval link | Human. The partner app cannot approve itself |
| Map the technician security group | `POST .../accessAssignments` | Portal, once status is `active` |
| Auto-extend by 180 days | `PATCH` `autoExtendDuration: P180D` | Portal, on an active relationship that does **not** include Global Administrator |

Approval link the strip shows (staff only, never emailed by the portal):

`https://admin.microsoft.com/AdminPortal/Home#/partners/invitation/granularAdminRelationships/{relationshipId}`

An unapproved request expires after **90 days**. The next audit creates a fresh one. Names are `On IT {client id} {yymmdd}` so a dead relationship's name is not reused (Microsoft blocks reuse for 365 days).

A relationship with **no security-group assignment** can show as Active in Partner Center while technicians still cannot sign in. The portal treats that as not ready.

**Global Administrator cannot auto-extend.** The standing request omits it. Privileged Role Administrator is enough to Accept the portal Graph app and to assign Exchange Administrator on that app for DKIM. A relationship that already includes Global Administrator is left in place when it covers the standing roles. The strip warns when that one is inside 30 days of its end date, because Microsoft will not extend it.

Pax8's GDAP belongs to Pax8. It does not sign On IT technicians in.

## Standing roles

Defined in `App\Services\Gdap\GdapRoleSet`. One relationship, duration `P730D`, auto-extend `P180D`.

| Role | Template id |
|---|---|
| Privileged Role Administrator | `e8611ab8-c189-46e8-94e1-60213ab1f814` |
| Application Administrator | `9b895d92-2cd3-44c7-9d02-a6ac2d5ea5c3` |
| Exchange Administrator | `29232cdf-9323-42fd-ade2-1d097af3e4de` |
| User Administrator | `fe930be7-5e62-47db-91af-98c3a49a38b1` |
| Groups Administrator | `fdd7a751-b60b-444a-984c-02652fe8fa1c` |
| Security Administrator | `194ae4cb-b126-40b2-bd5b-6091b380977d` |
| Privileged Authentication Administrator | `7be44c8a-adaf-4e2a-84d6-ab2649eac61a` |
| Helpdesk Administrator | `729827e3-9c14-49f7-bb1b-9608f156bbb8` |
| Service Support Administrator | `f023fd81-a637-4b56-95fd-791ac0226033` |

Roles on an approved relationship cannot be added later. If an active relationship is missing any of these, the audit creates a **second** request for the full set (unless one is already waiting on approval).

## When Connect Microsoft is blocked

Only when `GDAP_ENABLED=true` **and** the partner app is configured (partner tenant id, client id, secret).

| Situation | Connect / Re-consent |
|---|---|
| Flag off | Allowed. No strip |
| Flag on, app not configured | Allowed. Strip says what is missing |
| Not checked yet, and the client has a tenant id or a main domain | **Blocked** until Check GDAP now has run |
| `ready` or `expiring` (active, required roles, technician group assigned) | Allowed. Expiring is a warning; access still works |
| `approval_pending`, `unassigned`, `incomplete`, `missing` | **Blocked** |
| `needs_tenant` (no tenant id and the main domain is not a Microsoft tenant) | Allowed. Paste the Entra tenant ID, then Check GDAP now |
| Last check threw (Graph down) | Keeps the previous block if there was one. A first-time failure does not block |

The Accept URL is hidden while Connect is blocked, including the copy box inside steps 03 and 04, so the link cannot be used around the strip.

The audit does **not** write `entra_tenant_id`. That column stays owned by Connect / the Edit form. When it is empty, the audit reads the public OpenID metadata for `primary_domain` and stores the discovered tenant id only inside `gdap_snapshot.customer_tenant_id`.

## Snapshot

`clients.gdap_snapshot` (JSON), written by the audit, never by the Edit form.

`status`: `ready`, `expiring`, `approval_pending`, `unassigned`, `incomplete`, `missing`, `needs_tenant`, `error`, or absent (`unchecked`).

`blocks_connect` is the gate the setup guide reads. Staff pages are the only consumers. Customers do not see the snapshot or the approval link.

## Schedule and button

- `portal:audit-gdap` daily at **04:40** (`routes/console.php`). It queues `AuditGdapRelationshipsJob` (one pass, all active clients). `--client=` limits the job to one id.
- **Check GDAP now** on Edit Client queues the same job for that client.
- Saving a client while the flag is on queues a check for that client.
- The job takes one partner-tenant token, lists relationships once, then reconciles each client. A per-client cache lock stops a double click creating two requests.
- Graph errors are stored on that client and the loop continues.

Integration Health (staff) shows a GDAP block: off, not configured, or counts plus the problem clients. It reads the snapshot only. It does not call Graph during the 5-second poll.

## One-time setup in the On IT tenant

Do this before `GDAP_ENABLED=true` on a new install. Production already has the flag and partner tenant (2026-11-13). Still missing there: the Graph permission, the service principal below, and `GDAP_TECHNICIAN_GROUP_ID`.

1. Confirm the app registration is the portal app in the **On IT** tenant (the same app as `ENTRA_SYNC_CLIENT_ID` / `MICROSOFT_CLIENT_ID`, unless you set a dedicated GDAP app).
2. Add application permission **DelegatedAdminRelationship.ReadWrite.All** and grant admin consent **in the On IT tenant**. Do not re-consent this permission into customer tenants.
3. Provision the service principal Microsoft requires for app-only GDAP calls: appId `2832473f-ec63-45fb-976f-5d45a7d4bb91` (Partner Customer Delegated Administration). As an Application Administrator in the On IT tenant:

```http
POST https://graph.microsoft.com/v1.0/servicePrincipals
Content-Type: application/json

{ "appId": "2832473f-ec63-45fb-976f-5d45a7d4bb91" }
```

A 409 means it already exists. That is fine.

4. Create a security group in the On IT tenant, put technicians in it, and set `GDAP_TECHNICIAN_GROUP_ID` to its object id. One group can cover every customer. The audit assigns that group to the standing roles on each active relationship.
5. Set `GDAP_PARTNER_TENANT_ID` to the On IT tenant id (a GUID).
6. `GDAP_ENABLED=true`, then `php artisan config:clear` (or the usual deploy `optimize`). Run `php artisan portal:audit-gdap` once and watch Integration Health.

Tokens for this feature are taken **in the On IT tenant**. Customer-tenant Entra sync tokens are unchanged.

```
GDAP_ENABLED=false
GDAP_PARTNER_TENANT_ID=
# Optional. Blank uses ENTRA_SYNC_CLIENT_ID / MICROSOFT_CLIENT_ID and the matching secret.
# GDAP_CLIENT_ID=
# GDAP_CLIENT_SECRET=
GDAP_TECHNICIAN_GROUP_ID=
```

## Customer approval

The portal does not email customers. The strip shows the approval link to technicians.

- When On IT already has a Global Admin path into that tenant, a technician opens the link and approves.
- When there is no path at all, a customer Global Admin has to approve that single Microsoft page. That is the one step Microsoft will not let a partner complete. Do not send them the rest of the checklist or the Connect Microsoft link.

## Code

| Piece | Where |
|---|---|
| Roles | `app/Services/Gdap/GdapRoleSet.php` |
| Graph | `app/Services/Gdap/GdapGraphClient.php` |
| Decide | `app/Services/Gdap/GdapRelationshipAssessor.php` |
| Apply | `app/Services/Gdap/GdapReconcileService.php` |
| Edit / IH view model | `app/Services/Gdap/GdapSnapshotPresenter.php` |
| Job | `app/Jobs/AuditGdapRelationshipsJob.php` |
| Command | `portal:audit-gdap` |
| Strip | `resources/views/admin/clients/_gdap-strip.blade.php` |

## Change log

| Date | Change |
|---|---|
| 2026-11-13 | Production flag on. Partner tenant set from the onit.ltd directory. Technician group id still empty. |
| 2026-11-12 | Full lifecycle: nightly audit, create, lock for approval, assign technician group, auto-extend, Edit Client strip, Connect gate, Integration Health. Code default stays off until the On IT tenant app is consented. |
