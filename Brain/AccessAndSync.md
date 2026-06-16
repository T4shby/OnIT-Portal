# Access control and user sync

Answers for: *Do we need auto-sync from M365? What happens when an account is closed?*

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

### Recommended Phase 2 design (not built yet)

1. **One Entra security group per client** in that client's tenant:  
   `On IT Portal - {Company Name}`
2. **Scheduled sync job** (or SCIM): group members → `users` table  
   - Join group → create user (or activate) with correct `client_id`  
   - Leave group → `is_active = false`  
   - Disabled in Entra → `is_active = false`
3. **SuperOps API** (Phase 3): create/update/disable requesters when portal user changes

### What Tom should do now (before auto-sync exists)

| Priority | Action |
|---|---|
| 1 | Standardise naming: SuperOps client name = portal client name |
| 2 | Colleague owns portal + SuperOps user rows using spreadsheet |
| 3 | Tom owns Client SSO once per tenant (60 one-time setups) |
| 4 | Document offboarding: both teams use the checklist above |
| 5 | Plan Phase 2 sync in [Roadmap.md](Roadmap.md) when volume hurts |

---

## Bulk onboarding tips (60 clients)

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
