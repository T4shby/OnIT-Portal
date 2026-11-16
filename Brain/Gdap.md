# GDAP lifecycle (partner admin relationship)

On IT technicians sign into a customer tenant with **granular delegated admin privileges (GDAP)**. That is a Partner Center admin relationship between the **On IT partner tenant** and the customer tenant.

Connect Microsoft (checklist steps 03 and 04) is a different grant. It lets the portal app call Graph in the customer tenant. A client can be fully onboarded and still have no GDAP. This feature closes that gap.

Steps **04** (Portal Graph Accept) and **08** (SuperOps Client SSO) stay on those numbers. GDAP is a strip above the setup guide, not a new numbered step.

The code default is **off**. Production was turned on 2026-11-13. The partner tenant is the onit.ltd directory, and `GDAP_TECHNICIAN_GROUP_ID` was set on 2026-11-16. See [one-time setup](#one-time-setup-in-the-on-it-tenant).

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
| Helpdesk Administrator | `729827e3-9c14-49f7-bb1b-9608f156bbb8` |
| Service Support Administrator | `f023fd81-a637-4b56-95fd-791ac0226033` |

Roles on an approved relationship cannot be added later. If an active relationship is missing any of these, the audit creates a **second** request for the full set (unless one is already waiting on approval).

Microsoft rejects **Privileged Authentication Administrator** (`7be44c8a-adaf-4e2a-84d6-ab2649eac61a`) on create with `400 badRequest`. It is not in the standing set. The other eight roles, `P730D`, and `P180D` auto-extend are accepted together.

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

Do this before `GDAP_ENABLED=true` on a new install. Production has the flag, the partner tenant, the Graph permission, the service principal, and `GDAP_TECHNICIAN_GROUP_ID` (2026-11-16).

Sign in as `@onit.ltd` in a normal browser window. The directory name must be **On IT Technology Partners LTD**. A private window that was used to sign into a customer is the wrong tenant. Do not re-consent this permission into customer tenants. Customer Connect Microsoft stays as it is.

### 1. Permission on OnIT Portal for Portals

Same app as [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) Step 0.

1. Open [https://portal.azure.com](https://portal.azure.com).
2. Search **Microsoft Entra ID** and open it.
3. Left menu **App registrations**.
4. Open **OnIT Portal for Portals**.
5. Left menu **API permissions**.
6. **+ Add a permission**.
7. **Microsoft Graph**.
8. **Application permissions** (the right-hand tab, not Delegated).
9. Search `DelegatedAdminRelationship`.
10. Tick **DelegatedAdminRelationship.ReadWrite.All**.
11. **Add permissions**.
12. **Grant admin consent for On IT Technology Partners LTD**, then **Yes**.

The new row must show a green tick, **Granted for On IT Technology Partners LTD**. Status **Not granted** means the Grant button was skipped.

### 2. Microsoft service principal

This is a Microsoft app, not a new app registration. App id `2832473f-ec63-45fb-976f-5d45a7d4bb91`, display name **Partner Customer Delegated Administration**.

1. Open [Graph Explorer](https://developer.microsoft.com/en-us/graph/graph-explorer) and sign in with the same `@onit.ltd` Global Admin.
2. If it asks to consent Graph Explorer, accept. That consent is for Graph Explorer only.
3. Method **GET**, URL:

```
https://graph.microsoft.com/v1.0/servicePrincipals?$filter=appId eq '2832473f-ec63-45fb-976f-5d45a7d4bb91'&$select=id,displayName,appId
```

4. **Run query**. A row inside `value` means it already exists. Stop.
5. An empty `value` array means create it. Method **POST**, URL `https://graph.microsoft.com/v1.0/servicePrincipals`, request body:

```json
{ "appId": "2832473f-ec63-45fb-976f-5d45a7d4bb91" }
```

6. A 403 `Authorization_RequestDenied` ("Insufficient privileges") on the GET or the POST means Graph Explorer itself has not been consented. The portal app permission does not cover this tab. Open **Modify permissions**, consent **Application.ReadWrite.All**, then run the GET again.
7. **201** means created. **409** `Request_MultipleObjectsWithSameKeyValue` ("service principal name https://api.partnercustomeradministration.microsoft.com is already in use") means it already existed. Both are success. Do not create it again.

Check in Entra: **Enterprise applications**, search **Partner Customer Delegated Administration**. Application ID `2832473f-ec63-45fb-976f-5d45a7d4bb91`.

### 3. Technician security group object id

One security group in the On IT tenant. The Global Admin security group is fine. The Global Administrator **role** is not.

1. [https://entra.microsoft.com](https://entra.microsoft.com), same directory.
2. **Groups** → **All groups**.
3. Open the security group. Group type must be **Security**.
4. On **Overview**, copy **Object ID**.

If the page title is **Global Administrator** under **Roles and administrators**, that id is the role. Go back to **Groups** and open the group assigned to that role.

Paste the object id into the server `.env` as `GDAP_TECHNICIAN_GROUP_ID`, then `php artisan config:clear` and `php artisan optimize`. Do that the same day as steps 1 and 2. The 04:40 job will start creating requests once Graph answers, and it cannot attach technicians until this id is set.

`GDAP_PARTNER_TENANT_ID` and `GDAP_ENABLED=true` are already set in production.

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
| 2026-11-16 | Dropped Privileged Authentication Administrator. Graph create returns 400 when that role is included. The other eight roles with P730D and P180D succeed. Technician group id is set in production. |
| 2026-11-16 | Integration Health 500: `@php(...)` with `??` left the GDAP block uncompiled. Use an `@php` / `@endphp` block. |
| 2026-11-16 | One-time setup is click-by-click: permission, Partner Customer Delegated Administration service principal, security group object id. Do not re-consent customers. |
| 2026-11-16 | 04:40 audit: 14 active clients error `Access to the resource is restricted`, 1 `needs_tenant`. No relationships created. Group id still empty. |
| 2026-11-13 | Production flag on. Partner tenant set from the onit.ltd directory. Technician group id still empty. |
| 2026-11-12 | Full lifecycle: nightly audit, create, lock for approval, assign technician group, auto-extend, Edit Client strip, Connect gate, Integration Health. Code default stays off until the On IT tenant app is consented. |
