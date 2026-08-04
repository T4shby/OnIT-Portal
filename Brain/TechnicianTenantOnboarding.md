# MSP technician onboarding (zero training)

**This is the only technician-facing guide.** Open the live checklist and follow each numbered step. Requester Client SSO is configured in step 08.

Every step in the app shows **Where** (exact product + menu path) then numbered clicks. This page mirrors that so you know the map before you start.

**MSP ownership:** On IT technicians perform every setup and acceptance action on the customer’s behalf using delegated / GDAP access. The customer does not receive setup links, sign in, or complete checklist tasks.

## Start here

1. Sign in to https://app.onit.ltd as an On IT technician.
2. Go to **Admin → Clients → Add Client**.
3. Enter the company name (optional SuperOps/Pax8 fields if you already have them).
4. Click **Create client**.
5. The **Edit Client** page opens. Work the **Client setup Guide** on the **right**.

| Button | Where | What it saves |
|---|---|---|
| **Save client** | Left (orange) | IDs, licence tier, sync settings |
| **Save checklist** / tick | Right | Step ticks — ticking **Mark this step complete** auto-saves (Save checklist still works too) |

| Tool allowed | Not needed |
|---|---|
| This portal page | Server / SSH / Plesk |
| SuperOps MSP console | Platform SAML rebuild |
| Customer Microsoft Entra / M365 tenant through On IT GDAP | SuperOps Client SSO |

## Live checklist (Edit page)

**Before steps 03–04 — Connect Microsoft (preferred):**

1. **Private/incognito browser**.
2. Edit Client checklist → orange **Connect Microsoft tenant** (Accept Portal Graph).
3. Sign in with On IT **GDAP** so you land in the **customer** tenant (do **not** open On IT then switch).
4. **Accept** permissions.
5. Portal writes tenant ID, Free/P1 licence, portal group Object ID, SuperOps SCIM app ID, Client SSO app ID.

Manual Azure Overview → Licence paste is legacy fallback only if Graph permissions are missing.

| # | Step | Where you work | Done when |
|---|---|---|---|
| 01 | Link SuperOps client | SuperOps **Clients** → paste Account ID on portal left | SuperOps Account ID saved |
| 02 | Link Pax8 (or skip) | Pax8 **Companies** → or leave blank | Pax8 off, or company ID saved |
| 03 | Connect Microsoft tenant | **Connect Microsoft tenant** → bootstrap | Tenant ID + group ID saved |
| 04 | Accept Portal Graph | Same Connect button (GDAP Accept) | Consent + bootstrap, or first sync later |
| 05 | Get SuperOps SCIM tokens | SuperOps **Integrations → Microsoft Entra ID → Generate Tokens** | Tokens generated (copy ready) |
| 06 | SuperOps SCIM app | Usually auto after Connect; confirm Application (client) ID left | App ID on portal |
| 07 | SCIM tokens + mappings + start | Portal left **Apply SCIM credentials + start** (or Azure Provisioning) | Apply succeeds / provisioning On |
| 08 | Configure SuperOps Client SSO | Portal left **Configure SAML in Entra** (Entity ID + ACS from SuperOps) → paste Login URL + cert into SuperOps | SuperOps Client SSO enabled |
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
| Customer Azure | **Private/incognito browser** → https://portal.azure.com → sign in with GDAP into the **customer** tenant directly (never open On IT first then switch). Entra left **Manage** → Groups / Enterprise applications / App registrations |

### Step 07 Free vs P1 (do not mix)

| Licence tier | After mappings + App role Value **User** |
|---|---|
| **Entra ID Free** | App registrations → Overview → copy **Application (client) ID** (not Object ID) → portal left **SuperOps Application (client) ID** → Save client → Start provisioning |
| **Entra ID P1** | Enterprise app → **Users and groups** → assign `On IT Portal - {Company}` → Start provisioning |

## Rules that never change

- Work email must match in M365, SuperOps, and the portal.
- Group name is always `On IT Portal - {Company}` and starts empty.
- Customer SCIM app is `SuperOps - {Company}` (SCIM only — not SAML).
- SuperOps requester login uses **Client SSO** (step 08). Global SSO + customer Accept is retired.
- Portal Graph Accept (04) remains; step 08 is SAML setup, not a Microsoft Accept page.
- Entra ID Free: never add SSO users one-by-one. Save the customer Client SSO Application ID; step 10 **Sync now** assigns active licensed users.
- Customers do no onboarding work. If GDAP permissions are insufficient, escalate internally; never send them checklist actions or Accept URLs.
- Never reuse another customer's Entity ID, Consumer Service URL, Login URL or certificate. Details: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).
- Confirm customer **Entra ID Overview → License** before setting portal licence tier (P1 vs Free).
- Test login with a **customer** work email, never an On IT staff account.

## If you get stuck

Use these only when the live step is blocked or broken:

| Problem | Open |
|---|---|
| Full Entra / SCIM re-do | [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| SCIM mapping detail | [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| Client SSO / Entity ID / certificate | [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) |
| Why two syncs exist | [AccessAndSync.md](AccessAndSync.md) |
| Platform values / verification | [ClientOnboarding.md](ClientOnboarding.md) |

---

## Change log

| Date | Change |
|------|--------|
| 2026-08-04 | Connect Microsoft tenant: Accept + Graph bootstrap fills tenant/licence/group/app IDs (steps 03–04/06–08 Entra side) |
| 2026-08-04 | Customer Azure steps: private browser + log straight into customer tenant (no On IT→switch); Entra left **Manage** before Groups / Enterprise apps |
| 2026-07-14 | Replaced requester Global SSO Accept with customer-owned Client SSO in step 08 |
| 2026-07-14 | Licence check from Entra Overview before step 03 |
| 2026-07-14 | MSP ownership explicit: On IT technicians perform all setup via GDAP; customers receive no setup tasks or Accept links |
| 2026-07-14 | Entra ID Free: Client SSO Application ID lets Sync now assign active licensed users after step 08 |
| 2026-07-14 | Every live step now has separate **Where** blocks per app (Portal / SuperOps / Azure); Brain map lists product + menu path per step |
| 2026-07-14 | Restored full Entra click paths (create app, Admin Credentials, App roles, Application client ID, Users and groups) — simple words, complete how-to |
| 2026-07-14 | Free Application (client) ID path made explicit in step 07 (App registrations Overview, not Object ID); Azure work called out in guide header |
| 2026-07-14 | Canonical zero-training technician guide; mirrors live 12-step checklist |
| 2026-06-25 | SuperOps requester naming + Free app ID notes |
| 2026-06-16 | Initial guide |
