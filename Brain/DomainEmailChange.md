# Domain / primary email change (portal)

**When:** A customer renames primary SMTP / UPN (old becomes alias), e.g. whole tenant `@mxvi.net` → `@mxvi.com`.  
**Stable identity:** Microsoft Entra **object id**. Email is a changeable attribute.

## Expected behaviour (after object-id upsert fix)

| System | Primary email changes |
|--------|------------------------|
| **Portal Entra sync** | Same person → **one** row; `users.email` **updated** to Graph `mail` (else UPN). Role **preserved**. |
| **Login** | Match by object id first; refresh email when free and primary differs. |
| **Duplicates from the old bug** | Next sync **merges**: keeps object-id keeper (prefers elevated client role), retires shadow rows (`retired+…@portal.invalid`, inactive, object id cleared). |
| **SuperOps** | Portal does **not** rename SuperOps requesters via API. SCIM may **Update** or **Create** depending on SuperOps matching. Always check Provisioning logs. |

**Never invent a second portal user** when Graph only reports a new primary for an existing object id.

## Ops checklist (every domain cutover)

1. **Before cutover (ideal)**  
   - Note client id, Entra sync enabled, approximate user count.  
   - Prefer deploying **object-id-first sync** before bulk primary changes.

2. **In customer Entra**  
   - Complete primary address change (old proxy as alias).  
   - Confirm a sample user: Entra object id unchanged; **Primary SMTP** = new domain.

3. **Portal**  
   - Admin → Clients → **Edit** client → **Sync now** (or wait for hourly `portal:sync-entra-users`).  
   - Prefer one dry-run first if using Artisan:  
     `php artisan portal:sync-entra-users --client={id} --dry-run --inline`  
     then without `--dry-run`.

4. **Verify portal**  
   - Admin → Users for that client: count should **not** roughly double.  
   - Sample: same people on **new** domain; no active `old.domain` rows for those people.  
   - `client_admin` still admin on the kept row.  
   - Any `retired+…@portal.invalid` rows = merged shadows (inactive) — leave or hard-delete later offline.

5. **Verify SuperOps**  
   - Requesters show **new** addresses (or understand vendor lag).  
   - Entra enterprise app → **Provisioning logs**: prefer **Update**, investigate mass **Create**.  
   - Spot-check tickets still attach to the right requester.

6. **Spot-login**  
   - One client admin signs in with work account (new primary). Portal session should open the **same** account / role.

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
| Deactivate by object id | `usersRemovedFromScope` |
| Login object id + email refresh | `MicrosoftAuthController` |
| Sync 2 overview | [AccessAndSync.md](AccessAndSync.md), [EntraGroupSync.md](EntraGroupSync.md) |

## Changelog

| Date | Note |
|------|------|
| 2026-08-11 | Initial runbook + object-id-first portal identity |
