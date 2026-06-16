# Access control and user sync

## Should we build sync before giving staff instructions?

**Yes, for 60 tenants.** Do not hand colleagues a manual user-by-user process at full scale.

| Approach | When it makes sense |
|---|---|
| **Manual users (today)** | 1 to 3 pilot clients only, while you prove SSO and dashboard |
| **Build portal sync first** | Before rolling out to dozens of clients or delegating to staff |
| **Staff instructions after sync** | Colleague only creates the **client** record and maps the Entra group. Sync handles users. |

**What sync does not replace (you still do once per company):**

- Client SSO in each customer's Microsoft tenant (your M365 admin work)
- SuperOps client record + Client SSO in SuperOps
- Creating the portal **client** row and linking SuperOps Account ID

**What sync should replace (do not ask staff to do at scale):**

- Adding every user in **Admin → Users**
- Deactivating portal users when someone leaves (sync sets `is_active=false` when removed from group or disabled in Entra)

**SuperOps requesters** are a separate problem. Portal sync can go live first. SuperOps user create/disable via API is a later phase (or SuperOps bulk import in the meantime).

### Recommended order of work

1. **You (Tom):** Client SSO for pilot client(s). Confirm portal login works.
2. **Build:** ✅ Entra group → portal user sync on branch `feature/entra-group-sync`. See [EntraGroupSync.md](EntraGroupSync.md).
3. **You:** One security group per client in each tenant (e.g. `On IT Portal - {Company}`).
4. **Pilot:** One client on sync. Test join, leave, and disabled account.
5. **Then** give staff the slim guide: create client in SuperOps + portal, paste Entra group ID, Tom does SSO.
6. **Scale** to remaining tenants. SuperOps requesters: bulk import or manual until API exists.

---

## Short answers

| Question | Answer today (MVP) | Planned (Phase 2+) |
|---|---|---|
| Auto-create portal users from M365? | **No.** Admin must create each user in the portal. | Yes, via Entra group sync or SCIM |
| Auto-create SuperOps requesters from M365? | **No.** Manual in SuperOps (or bulk import). | SuperOps API provisioning (Phase 3) |
| Disabled M365 account → blocked from portal? | **Usually yes** at Microsoft login. Portal row may still exist if not deactivated. | Sync will set `is_active=false` when removed from group |
| Disabled M365 account → blocked from SuperOps? | **Not automatic.** Remove requester in SuperOps and Entra SSO assignment. | Same until API sync exists |
| One M365 admin for 60 tenants | Tom can do all Client SSO. Colleague does SuperOps + portal users. | Per-tenant groups + automated sync recommended at scale |

---

## How login works today

### On IT Portal (`app.onit.ltd`)

```
User clicks "Sign in with Microsoft"
        ↓
Microsoft checks their work account (any of 60 tenants can work; portal is multi-tenant OAuth)
        ↓
Portal looks up users.email in the database
        ↓
Found AND is_active = true  →  allowed in
Not found                   →  "Your account has not been set up"
Found AND is_active = false →  "Your account has been deactivated"
```

**Important:** The portal does **not** read your Entra groups or user list automatically. If you never create the user in **Admin → Users**, they cannot get in, even with a valid M365 account.

### SuperOps (`portal.onit.ltd`)

Separate system. User must exist as a **requester** on the client in SuperOps.  
**Client SSO** (per customer tenant) controls Microsoft sign-in to SuperOps.  
The portal **SuperOps** button launches SSO using global or client configuration.

On portal login, `SuperOpsUserSyncService` only **links** an existing SuperOps requester by email if `SUPEROPS_API_TOKEN` is set. It does **not** create new SuperOps users.

---

## When someone leaves (offboarding checklist)

Do **all** of these. Do not rely on one system alone.

### Tom (M365 admin)

1. Disable the user account in the **customer's** Microsoft 365 tenant, **or** remove their licence and block sign-in per your standard process.
2. Remove them from the **SuperOps Client SSO** enterprise app assignment in that tenant (or remove from the security group assigned to that app).
3. Optional: remove from any `Client - {Name} - Portal` group when group sync exists.

### Colleague (portal + SuperOps)

1. **Portal:** Admin → Users → Edit user → untick **Active** (preferred) or delete the user.
2. **SuperOps:** Clients → {Company} → Users → remove or deactivate the requester.

### What each step actually stops

| Step | Stops portal login? | Stops SuperOps? |
|---|---|---|
| Disable M365 account | Usually yes (Microsoft blocks OAuth) | Usually yes for SSO users |
| Portal `is_active = false` | Yes (even if M365 still active) | N/A |
| Remove SuperOps requester | N/A | Yes (no requester record) |
| Remove Entra SSO app assignment | N/A | Yes for SSO path |

**Gap today:** If M365 is disabled but portal user stays **Active**, the portal row is stale (harmless if Microsoft blocks login). If portal is deactivated but SuperOps requester remains, they might still use SuperOps direct URL with other auth methods if enabled.

**Best practice:** always deactivate in **all three**: M365, portal, SuperOps.

---

## Do you need auto-sync for 60 companies?

**For launch:** No, but you need a **repeatable process** (spreadsheet + [ColleagueSetupGuide.md](ColleagueSetupGuide.md)).

**For long term:** **Yes, recommended.** Manual steps for hundreds of users across 60 tenants will cause drift (orphan accounts, typos, slow onboarding).

## What to build (Phase 2)

Minimum viable sync for your 60-tenant model:

| Piece | Purpose |
|---|---|
| `entra_group_id` (or group name) on `clients` table | Which M365 group drives each portal client |
| Microsoft Graph app + admin consent per tenant | Read group members and user `accountEnabled` |
| Artisan command e.g. `portal:sync-entra-users` (scheduled hourly) | Create/update/deactivate `users` from group membership |
| Offboarding | Removed from group or `accountEnabled=false` → `is_active=false` on portal user |

Optional later: SCIM endpoint (Entra pushes to portal instead of pull). Graph pull is simpler to ship first when you already admin all tenants.

SuperOps Phase 3: when `SUPEROPS_API_TOKEN` can create/disable requesters, hook the same job or an event after portal user changes.

---

| Priority | Action |
|---|---|
| 1 | Standardise naming: SuperOps client name = portal client name |
| 2 | Colleague owns portal + SuperOps user rows using spreadsheet |
| 3 | Tom owns Client SSO once per tenant (60 one-time setups) |
### What to do now (before sync is built)

| Priority | Action |
|---|---|
| 1 | Pilot 1 to 2 clients manually to prove SSO + portal (not 60) |
| 2 | Build portal Entra group sync (do not delegate user entry to staff yet) |
| 3 | Standardise: one Entra group per client, same naming in SuperOps and portal |
| 4 | After sync works: staff guide for **client setup only** |
| 5 | SuperOps requesters: bulk import or manual until API sync |

### What Tom should do now (before auto-sync exists)

| Tip | Detail |
|---|---|
| SuperOps bulk import | Use SuperOps CSV import for requesters where available |
| Portal users | Still one-by-one in Admin today; export template from spreadsheet |
| Client SSO | Batch with Tom: 5 to 10 tenants per week with a tracker |
| Test account | One test requester per client before go-live |
| Do not use | `tom.ashby@onit.ltd` for requester SSO tests |

---

## Related documents

| Doc | Audience |
|---|---|
| [ColleagueSetupGuide.md](ColleagueSetupGuide.md) | Non-technical staff doing daily adds |
| [NewClientSetupGuide.md](NewClientSetupGuide.md) | Full step-by-step including Client SSO |
| [ClientOnboarding.md](ClientOnboarding.md) | Master reference |
| [Decisions.md](Decisions.md) ADR-014 | Why sync was deferred for MVP |

---

## Change log

| Date | Change |
|---|---|
| 2026-06-16 | Initial doc: MVP manual provisioning, offboarding, Phase 2 sync recommendation |
