# New client setup (quick pointer)

**Full guide for new technicians:** [NewCustomerTenantSetup.md](NewCustomerTenantSetup.md) — start there if you have never used this app.

**In-app wizard:** **Add Client** = form only. After **Create**, **Edit** opens with the setup checklist on the **right**; **Dry run sync** / **Sync now** on the **left**.

| URL | What |
|-----|------|
| https://app.onit.ltd | On IT Portal |
| https://portal.onit.ltd | SuperOps requester portal |

**Rule:** Work email must match in M365, SuperOps, and the portal.

**Portal sync (automatic):** Whole tenant — licensed users + shared mailboxes → portal users. **No group membership required for portal.**

**SuperOps sync (SCIM):** Security group `On IT Portal - {Company}` members only → SuperOps requesters. Add users to the group manually (Assigned) or use a Dynamic group on Entra ID P1.

**Client admins:** **Microsoft 365** menu → read-only directory (people + groups).

**Technician SSO (SuperOps/Pax8 for `@onit.ltd`):** already configured — not part of per-customer setup.

---

## Change log

| Date | Change |
|------|--------|
| 2026-06-16 | Preview guide on Add Client; sync buttons documented on left |
| 2026-06-23 | Point to NewCustomerTenantSetup.md; tenant-wide sync + M365 directory |
| 2026-06-19 | In-app setup wizard on client edit page |
| 2026-06-16 | Initial guide |
