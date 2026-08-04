# SuperOps Requester Client SSO — On IT Runbook

Canonical runbook for Microsoft Entra SAML sign-in to SuperOps for customer requesters.

**Official reference:** [Setting up Requester SSO in SuperOps](https://support.superops.com/en/articles/11583025-setting-up-requester-sso-in-superops)

## Decision

On IT uses **SuperOps Client SSO**, not Global SSO, for normal customers.

Each customer keeps its identities in its own Entra tenant:

```text
Customer user
  → On IT Portal
  → SuperOps requester portal
  → customer-specific SuperOps Client SSO
  → customer's Entra tenant
  → SuperOps requester
```

- No customer B2B guest accounts in the On IT tenant.
- One SuperOps Client SSO configuration per customer tenant.
- One non-gallery SAML enterprise application in that customer tenant.
- On IT technicians perform every action through SuperOps admin and GDAP.
- The customer receives no setup links or checklist tasks.
- Technician SSO, portal OAuth and SCIM are separate and unchanged.

## Why Global SSO was retired

SuperOps Global SSO is valid when every requester authenticates against one identity-provider directory. Its official setup assigns all allowed users to one Azure enterprise application.

That does not meet On IT's model of 50+ independent customer Entra tenants without B2B guests. The retired experiment converted the non-gallery Global SSO app to Multitenant and added per-customer admin consent. SuperOps does not document that flow, and Azure rejected SuperOps' fixed Global Entity ID `https://clientuser.superops.ai` under the Multitenant verified-domain rules.

Do not reuse:

- Shared app **SuperOps Requester SSO (On IT)** for customer login.
- Application ID `bf1c303e-6015-43f7-abb2-5dfe8f67a5a1`.
- The old checklist 08 `/adminconsent` URL.
- `SUPEROPS_REQUESTER_SSO_CLIENT_ID`.
- `SUPEROPS_REQUESTER_SSO_CONSENT_REDIRECT`.

## One-time transition from Global SSO

SuperOps states that Global SSO must be disabled before Client SSO can be used.

1. Schedule the change while requester login is already known to be broken or during a maintenance window.
2. SuperOps MSP → **Settings → Requester Login → SSO Protected**.
3. Record the existing Global SSO Login URL and certificate for rollback.
4. Disable **Global SSO**.
5. Do not delete the old On IT Entra app until the first Client SSO pilot passes.
6. Configure the first customer using the procedure below.
7. After successful testing, leave Global SSO disabled and roll out Client SSO customer by customer.
8. Retire the old shared Entra app only after no customer depends on it.

## Per-customer checklist 08

Naming pattern (use the real company name):

- SuperOps configuration: `{Company} Entra SSO`
- Customer Entra app: `SuperOps Requester SSO - {Company}`
- Access group: `On IT Portal - {Company}`

### A. Generate client-specific SuperOps values

1. SuperOps MSP → **Settings → Requester Login → SSO Protected → Client SSO**.
2. Click **+ Configuration**.
3. Give the configuration a clear customer name.
4. Select the matching SuperOps client.
5. Generate the client-specific values.
6. Copy:
   - **Entity ID**
   - **Consumer Service URL**
7. Keep this configuration open.

These values are customer-specific. Do not substitute the old Global Entity ID or Reply URL.

### B. Configure SAML from the portal (preferred)

**Admin → Clients → Edit {Company}** → **Configure Client SSO SAML in Entra**:

1. Paste SuperOps **Entity ID** and **Consumer Service URL**.
2. Click **Configure SAML in Entra**.
3. Copy **IDP Login URL** and **Certificate** shown on that page.
4. Paste into SuperOps Client SSO step 3 → Save / enable.

Graph uses Application.ReadWrite.All (and optionally Policy.ReadWrite.ApplicationConfiguration for claims). Azure Enterprise apps SAML blade is fallback only if Configure fails.

### C. Create the customer's Entra SAML application (legacy / fallback)

Using GDAP only when portal Configure SAML is unavailable:

1. Open Azure and switch to the customer directory.
2. Microsoft Entra ID → **Enterprise applications → New application**.
3. **Create your own application**.
4. Name it `SuperOps Requester SSO - {Company}`.
5. Choose **Integrate any other application you don't find in the gallery (Non-gallery)**.
6. Create the application.
7. **Single sign-on → SAML**.

### D. Basic SAML configuration (legacy / fallback)

Use the values copied from that customer's SuperOps Client SSO configuration:

- **Identifier (Entity ID):** client-specific SuperOps Entity ID; mark Default.
- **Reply URL (ACS):** client-specific SuperOps Consumer Service URL; mark Default. Leave **Index** blank (not required for a single ACS URL).
- **Sign on URL:** blank.
- **Relay State:** blank.
- **Logout URL:** blank.

Save.

Do not use:

- `https://clientuser.superops.ai` unless SuperOps generated it for this Client SSO configuration.
- `https://onit.ltd/superops-requester-sso`.
- The On IT portal consent-complete URL.
- A Reply URL from another customer.

### E. Exact SAML claims

Azure **Attributes & Claims** (portal Configure tries to set these via claims mapping policy):

| Claim name | Source |
|---|---|
| `email` | `user.mail` |
| `firstname` | `user.givenname` |
| `lastname` | `user.surname` |

Claim names are lowercase and case-sensitive.

### F. Connect Azure back to SuperOps

**Preferred:** use Login URL + certificate from the portal after Configure SAML.

**Fallback:**

1. Azure **SAML Certificates** → download **Certificate (Base64)**.
2. Open it as text.
3. Copy the certificate body without the `BEGIN CERTIFICATE` / `END CERTIFICATE` marker lines.
4. Azure SAML page, section **Set up {application name}** → copy **Login URL**.
5. Return to SuperOps Client SSO.
6. Paste:
   - Azure **Login URL**
   - Certificate body
7. Confirm the correct SuperOps client is selected.
8. Save / enable the Client SSO configuration.

Do not reuse the On IT Global SSO Login URL or certificate. SuperOps explicitly says Azure credentials are not reusable between Global and Client SSO.

### G. Assign requester access

#### Entra ID P1 or higher

1. Customer Entra → Enterprise applications → customer SSO app.
2. **Users and groups → Add user/group**.
3. Assign `On IT Portal - {Company}`.

One group assignment covers the managed requester population. No On IT guest accounts are created.

#### Entra ID Free

Group assignment to enterprise applications is unavailable.

1. Customer Entra → App registrations → customer SSO app → **Overview**.
2. Copy **Application (client) ID**, not Object ID.
3. Portal → Admin → Clients → Edit customer.
4. Paste into **SuperOps Client SSO Application (client) ID**.
5. Save client.
6. Mark checklist 08 complete.
7. Run checklist 10 **Sync now**.

The portal assigns active licensed users directly to the customer-owned SSO enterprise app. These are assignments in the customer's tenant, not guest accounts in On IT.

## Test

1. Confirm the requester exists in SuperOps through SCIM with the same work email.
2. Use an InPrivate window.
3. Sign into `https://app.onit.ltd` as a real customer user.
4. Open the SuperOps tile.
5. Choose requester login if SuperOps shows a role chooser.
6. Confirm the customer-specific Client SSO sends the user to that customer's Entra tenant.
7. Confirm Microsoft sign-in returns to the SuperOps requester portal.

Never test customer requester SSO with an On IT technician identity.

## Troubleshooting

| Symptom | Cause / action |
|---|---|
| Account is sent to On IT tenant | Old Global SSO still active or old Login URL reused; disable Global and verify this client's Azure Login URL |
| AADSTS700016 for `clientuser.superops.ai` | Old Global Multitenant app is still being used; use the Client SSO values generated for this customer |
| SuperOps says email missing | Claim must be lowercase `email` and normally source `user.mail` |
| User not assigned | P1: assign Portal group; Free: save Client SSO Application ID and run Sync now |
| Another customer's login page appears | Wrong Client SSO configuration, Entity ID, Consumer URL or client association |
| SAML response rejected | Recheck customer-specific Entity ID, Consumer Service URL, certificate and claims |

## Retired components

The portal no longer exposes the old requester SSO admin-consent button or callback route. Checklist 04 remains the separate **Portal Graph Accept** and is still required.

Existing checklist key `superops_client_sso_configured` is retained so completed client records remain compatible; its meaning is now “customer Client SSO configured.”

The deployment migration clears that checkpoint on existing clients because any previous completion meant only that the retired Global SSO admin Accept had run. Technicians must complete the new step 08 before marking it done again.

## Change log

| Date | Change |
|---|---|
| 2026-08-04 | Pilot client names removed from checklist 08 examples; use `{Company}` pattern only |
| 2026-07-17 | Leave Reply URL Index blank; documented in Basic SAML steps |
| 2026-07-14 | Existing step 08 completion is reset during migration so old Global Accept records cannot masquerade as completed Client SSO |
| 2026-07-14 | Replaced unsupported Global SSO Multitenant adminconsent design with SuperOps Client SSO per customer tenant |
| 2026-07-14 | Added distinct per-client SSO Application ID for Entra Free automatic user assignment |
