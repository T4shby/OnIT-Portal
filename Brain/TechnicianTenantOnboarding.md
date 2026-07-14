# MSP technician onboarding (zero training)

**This is the only technician-facing guide.** Open the live checklist and follow each numbered step. Do not start from old Path A/B or Client SSO docs.

Every step in the app shows **Where** (exact product + menu path) then numbered clicks. This page mirrors that so you know the map before you start.

## Start here

1. Sign in to https://app.onit.ltd as an On IT technician.
2. Go to **Admin → Clients → Add Client**.
3. Enter the company name (optional SuperOps/Pax8 fields if you already have them).
4. Click **Create client**.
5. The **Edit Client** page opens. Work the **Client setup Guide** on the **right**.

| Button | Where | What it saves |
|---|---|---|
| **Save client** | Left (orange) | IDs, licence tier, sync settings |
| **Save checklist** | Right | Step ticks |

| Tool allowed | Not needed |
|---|---|
| This portal page | Server / SSH / Plesk |
| SuperOps MSP console | Platform SAML rebuild |
| Customer Microsoft Entra / M365 admin | SuperOps Client SSO |

## Live checklist (Edit page)

Set **Customer Entra license tier** on the left before step 03.

| # | Step | Where you work | Done when |
|---|---|---|---|
| 01 | Link SuperOps client | SuperOps **Clients** → paste Account ID on portal left | SuperOps Account ID saved |
| 02 | Link Pax8 (or skip) | Pax8 **Companies** → or leave blank | Pax8 off, or company ID saved |
| 03 | Create Portal group + save Entra IDs | Customer Azure **Groups** → paste Tenant + group IDs on portal left | Tenant ID + group ID saved |
| 04 | Customer Accepts Portal access | Orange Accept on checklist → verify **OnIT Portal for Portals** permissions | Accept used / or first sync later |
| 05 | Get SuperOps SCIM tokens | SuperOps **Integrations → Microsoft Entra ID → Generate Tokens** | Tick complete |
| 06 | Create SuperOps SCIM app in Entra | Customer Azure **Enterprise applications → New application** → Provisioning Admin Credentials → **Test Connection** | Tick complete |
| 07 | Azure SCIM mappings + Application ID (Free) / assign group (P1) + start | Customer Azure Attribute mapping + App roles + start provisioning | Tick complete |
| 08 | Customer Accepts SuperOps login | Orange **Open customer SuperOps SSO Accept page** (or **Copy link** for customer GA) → customer Azure **SuperOps Requester SSO (On IT) → Users and groups** | Accept completed and users/group assigned |
| 09 | Turn on portal sync | Portal left → **Entra sync enabled** → Save client | Dry run / Sync now visible |
| 10 | Run Dry run then Sync now | Portal left buttons → verify Azure group/logs + SuperOps Requesters | Last synced shows |
| 11 | Test as a customer user | Incognito → app.onit.ltd → SuperOps tile | Tick complete |
| 12 | Hand off to the customer | Email / ticket | Tick complete |

Steps 05–07 are one SCIM job split so a new technician can finish each screen without guessing. Older clients that already had SCIM marked complete stay complete.

### Where each product lives

| Product | Open this |
|---|---|
| On IT Portal | https://app.onit.ltd → **Admin → Clients → Edit {Company}** |
| SuperOps MSP | SuperOps portal URL from config (technician console) → **Clients** / **Integrations** |
| Pax8 | https://app.pax8.com → **Companies** |
| Customer Azure | https://portal.azure.com → top-right directory switcher → **customer name** (never stay in On IT Technology Partners LTD) |

### Step 07 Free vs P1 (do not mix)

| Licence tier | After mappings + App role Value **User** |
|---|---|
| **Entra ID Free** | App registrations → Overview → copy **Application (client) ID** (not Object ID) → portal left **SuperOps Application (client) ID** → Save client → Start provisioning |
| **Entra ID P1** | Enterprise app → **Users and groups** → assign `On IT Portal - {Company}` → Start provisioning |

## Rules that never change

- Work email must match in M365, SuperOps, and the portal.
- Group name is always `On IT Portal - {Company}` and starts empty.
- Customer SCIM app is `SuperOps - {Company}` (SCIM only — not SAML).
- SuperOps login uses **Global SSO + customer Accept** (step 08). Never Client SSO.
- Portal Graph Accept (04) and SuperOps Accept (08) are different Microsoft Accept pages.
- Step 08's customer Accept button remains visible after Done so technicians can copy or repeat the tenant-specific acceptance.
- Test login with a **customer** work email, never an On IT staff account.

## If you get stuck

Use these only when the live step is blocked or broken:

| Problem | Open |
|---|---|
| Full Entra / SCIM re-do | [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| SCIM mapping detail | [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| Multitenant / Accept / Entity ID | [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) |
| Why two syncs exist | [AccessAndSync.md](AccessAndSync.md) |
| Platform values / verification | [ClientOnboarding.md](ClientOnboarding.md) |

---

## Change log

| Date | Change |
|------|--------|
| 2026-07-14 | Fixed step 08: customer SuperOps SSO Accept / Copy link remains visible after the step is Done |
| 2026-07-14 | Every live step now has separate **Where** blocks per app (Portal / SuperOps / Azure); Brain map lists product + menu path per step |
| 2026-07-14 | Restored full Entra click paths (create app, Admin Credentials, App roles, Application client ID, Users and groups) — simple words, complete how-to |
| 2026-07-14 | Free Application (client) ID path made explicit in step 07 (App registrations Overview, not Object ID); Azure work called out in guide header |
| 2026-07-14 | Canonical zero-training technician guide; mirrors live 12-step checklist |
| 2026-06-25 | SuperOps requester naming + Free app ID notes |
| 2026-06-16 | Initial guide |
