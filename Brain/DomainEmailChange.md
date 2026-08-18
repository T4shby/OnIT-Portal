# Domain / primary email change (portal)

**When:** A customer renames primary SMTP / UPN (old becomes alias), e.g. whole tenant `@mxvi.net` → `@mxvi.com`.  
**Stable identity:** Microsoft Entra **object id**. Email is a changeable attribute.

## Expected behaviour (after object-id upsert fix)

| System | Primary email changes |
|--------|------------------------|
| **Portal Entra sync** | Same person → **one** row; `users.email` **updated** to Graph `mail` (else UPN). Role **preserved**. |
| **SuperOps requesters** | After the same Sync (background job): (1) bind `portal.superops_user_id` when matched; (2) SuperOps `updateClientUser` email = M365 primary. Match: SuperOps id → primary → **Graph aliases** (`proxyAddresses` / `otherMails`) → unique local-part. Unmatched count is in `EntraSyncResult` / activity + `entra_sync.last_result.{client}` (integration health), not a post-redirect flash - **Sync now** only confirms “started in background”. |
| **Login** | Match by object id first; refresh email when free and primary differs. |
| **Duplicates from the old bug** | Next sync **merges** portal shadows; SuperOps email pass then points the live requester at `.com`. |
| **SCIM still matters** | Names via `extensionAttribute1`, create/deprovision. Email renames can **lag or stick** in SCIM - API align is the enforcement after portal knows the truth. |

**Never invent a second portal user** when Graph only reports a new primary for an existing object id.

Env: `ENTRA_SYNC_SUPEROPS_EMAIL_ALIGN=true` (default) - set `false` only to pause SuperOps email API writes.

## Ops checklist (every domain cutover)

1. **Before cutover (ideal)**  
   - Note client id, Entra sync enabled, approximate user count.  
   - Prefer deploying **object-id-first sync** before bulk primary changes.

2. **In customer Entra**  
   - Complete primary address change (old proxy as alias).  
   - Confirm a sample user: Entra object id unchanged; **Primary SMTP** = new domain.

3. **Portal**  
   - Admin → Clients → **Edit** client → **Sync now** (or wait for adaptive `portal:sync-entra-users`).  
   - Job runs in the **background** (avoids nginx 504). Wait 1-2 minutes, then check **Last synced** and SuperOps requesters.  
   - Summary string (including SuperOps email align counts) is stored on `entra_sync.last_result.{id}` for Integration Health / ops - **not** shown as an on-page flash after Sync now.  
   - Prefer one dry-run first if using Artisan:  
     `php artisan portal:sync-entra-users --client={id} --dry-run --inline`  
     then without `--dry-run`.

4. **Verify portal**  
   - Admin → Users / M365 directory: **new** domain, not doubled.  

5. **Verify SuperOps**  
   - Requesters → same people with **new** primary email (not leftover only-old domain).  
   - Spot-check tickets still on the same SuperOps userId after email change.  
   - If some stay on old domain: check they share unique local-part with portal, SuperOps API token works, and no second SuperOps row already holds the new email.

6. **Spot-login**  
   - One client admin signs in with work account (new primary).

## If something is wrong

| Symptom | Likely cause | Action |
|---------|--------------|--------|
| Two actives, same person, old+new email | Sync not run yet, or pre-fix dups before merge | **Sync now** once; re-check users list |
| `Email … already belongs to manual user` | Manual portal row owns new address | Resolve manually (repoint / delete manual) |
| `Email … another client` | Cross-tenant email clash | Staff resolve uniqueness |
| SuperOps double requesters | SCIM matched by email | SuperOps consolidate; do not invent portal fix for SuperOps |
| Admin lost elevation | Rare if shadow held admin and keeper resolution picked wrong order | Restore role on kept row in Admin → Users |

## Related code

| Piece | Path |
|-------|------|
| Upsert / email claim / merge | `EntraGroupSyncService` (`resolvePortalUserForGraphIdentity`, `claimPrimaryEmailForUser`) |
| Graph user + aliases | `MicrosoftGraphClient::listTenantMemberUsers` → `emailAliases` / `normalizeGraphEmailAliases` |
| SuperOps email align + bind | `SuperOpsUserSyncService::alignRequesterPrimaryEmails` |
| Env gate | `config('services.entra_sync.superops_email_align')` ← `ENTRA_SYNC_SUPEROPS_EMAIL_ALIGN` |
| Async job + last result cache | `SyncEntraClientJob` → `entra_sync.last_result.{clientId}` |
| Deactivate by object id | `usersRemovedFromScope` |
| Login object id + email refresh | `MicrosoftAuthController` |
| Sync 2 overview | [AccessAndSync.md](AccessAndSync.md), [EntraGroupSync.md](EntraGroupSync.md) |

## Changelog

| Date | Note |
|------|------|
| 2026-08-11 | Docs: Sync now is **async** (no post-redirect summary flash); code map for align bind/aliases |
| 2026-08-11 | SuperOps: **eager superops_user_id bind**, Graph **alias** match, unmatched counts on Sync summary |
| 2026-08-11 | SuperOps **email align** via `updateClientUser` after Entra Sync (M365 primary) |
| 2026-08-11 | Initial runbook + object-id-first portal identity |
