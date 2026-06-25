# New client setup (quick pointer)

**Full guide for new technicians:** [NewCustomerTenantSetup.md](NewCustomerTenantSetup.md) — start there if you have never used this app.

**In-app wizard:** **Add Client** = form only. After **Create**, **Edit** opens with the setup checklist on the **right** (10 steps, install-manual format); **Dry run sync** / **Sync now** on the **left**.

**Checklist order (Edit page):** Add Client → Create first, then 01 SuperOps → 02 Pax8 (optional) → 03 security group → **04 admin consent** → **05 SCIM** → **06 Client SSO** → 07 enable sync → 08 run sync → 09 test → 10 handoff.

| URL | What |
|-----|------|
| https://app.onit.ltd | On IT Portal |
| https://portal.onit.ltd | SuperOps requester portal |

**Rule:** Work email must match in M365, SuperOps, and the portal.

**Portal sync (automatic):** Whole tenant — licensed users + shared mailboxes → portal users. **No group membership required for portal.**

**SuperOps sync (SCIM):** Security group `On IT Portal - {Company}` — portal **auto-fills** members (licensed users + shared mailboxes). On **Entra ID Free**, set **SuperOps Application (client) ID** on the client — portal assigns the same scope to the SCIM app. Requester names: `Name (User)` or `Name (Shared Mailbox)` via **`extensionAttribute1` + SCIM displayName Expression** (not M365 `displayName`). One Entra app for SCIM + SAML.

**Full runbook:** [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md)

**Client admins:** **Microsoft 365** menu → read-only directory (people + groups).

**Technician SSO (SuperOps/Pax8 for `@onit.ltd`):** already configured — not part of per-customer setup.

---

## Change log

| Date | Change |
|------|--------|
| 2026-06-24 | Install-manual checklist format; step numbers 05–07 aligned |
| 2026-06-25 | SuperOps requester naming + `entra_superops_app_id` on Entra ID Free documented |
| 2026-06-16 | Preview guide on Add Client; sync buttons documented on left |
| 2026-06-23 | Point to NewCustomerTenantSetup.md; tenant-wide sync + M365 directory |
| 2026-06-19 | In-app setup wizard on client edit page |
| 2026-06-16 | Initial guide |
