# On IT Portal - SuperOps Integration

## Overview

SuperOps is a **first-class integration**: portal support **list + create**, plus **SSO launch** for full requester/technician conversation (comments, attachments, workflow). One Microsoft login covers the portal; ticket conversation depth intentionally stays in SuperOps (ADR-022).

## Architecture

```
Microsoft Entra ID → On IT Portal session
                     ├── /support (GraphQL API - list / create / subject + opening description)
                     └── /integrations/superops/launch (SSO to full portal for threads)
```

### Pillar 1 - Support list & create (not full PSA UI)

- Route: `GET /support`, `POST /support`, `GET /support/{id}`, create form.
- `SuperOpsApiClient` → `https://api.superops.ai/msp` (or EU endpoint)
- Headers: `Authorization: Bearer <SUPEROPS_API_TOKEN>`, `CustomerSubDomain: <SUPEROPS_SUBDOMAIN>`, `Content-Type: application/json`
- Server-side `SUPEROPS_API_TOKEN` - never exposed to browser
- Tickets filtered by requester email / `users.superops_user_id`
- UI copy: threads and attachments open in SuperOps - every support page surfaces **Open SuperOps**
- Client Admin organisation metrics: [ClientAdminDashboard.md](ClientAdminDashboard.md#superops-graphql-request-shape)
- **createTicket contract** (all clients): [below](#createticket-contract-all-clients)

### Pillar 2 - SSO launch

- Route: `GET /integrations/superops/launch`
- **Technicians** (`super_admin`, `account_manager`) → same host as requester (`portal.onit.ltd`) + `/#/technician/login`
- **Client users** (`client_requester`, `client_billing_admin`, `client_admin`) → requester portal + `/#/requester/login` (when `superops_sso_enabled`)
- Launch URL priority (requester path):
  1. `SUPEROPS_REQUESTER_PORTAL_URL` or `https://{subdomain}.superops.ai` + `/#/requester/login`
  2. Optional `SUPEROPS_SSO_URL` only if it is a **SuperOps** URL (never `login.microsoftonline.com`)

**Important:** Do **not** put an Entra SAML Login URL in portal `SUPEROPS_SSO_URL`. It requires a `SAMLRequest` from SuperOps. Each customer Login URL belongs in that customer's **SuperOps Client SSO** configuration.

**Important:** `https://app.superops.ai/login/sso` does **not** exist (404). The MSP app URL and the client portal URL are different products.

### Login bootstrap

On Microsoft callback:

1. Store encrypted `microsoft_tokens` on user
2. `SuperOpsUserSyncService` links requester by email
3. `SuperOpsSsoService::establishSsoSession()`

## Environment

```env
# Required for SuperOps dashboard card to open anything:
SUPEROPS_SUBDOMAIN=onitltd
# Optional override (defaults to https://{SUPEROPS_SUBDOMAIN}.superops.ai):
# SUPEROPS_REQUESTER_PORTAL_URL=https://onitltd.superops.ai

# Required for embedded /support tickets (optional if you only use SSO launch):
SUPEROPS_API_TOKEN=
SUPEROPS_REGION=us
# createTicket requestType (MSP-wide; SuperOps treats this as mandatory)
SUPEROPS_DEFAULT_REQUEST_TYPE=Incident

# Requester Client SSO is configured in SuperOps per customer, not portal .env:
# SUPEROPS_SSO_URL=   ← leave empty
# Launch path (default skips role chooser):
SUPEROPS_REQUESTER_LOGIN_PATH=/#/requester/login
SUPEROPS_LOGIN_HINT_ENABLED=true

# Technician launch - same host as requester (defaults from SUPEROPS_REQUESTER_PORTAL_URL):
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_TECHNICIAN_LOGIN_PATH=/#/technician/login
# Optional override; legacy MSP app URL - not used for SSO launch:
# SUPEROPS_PORTAL_URL=https://app.superops.ai
```

### Role chooser (requester vs technician)

SuperOps SPA routes (from production bundle):

| Hash route | Purpose |
|---|---|
| `/#/login` | Requester **or** technician chooser |
| `/#/requester/login` | **Requester login directly** - portal uses this |
| `/#/technician/login` | Technician login |
| `/#/login/customer` | Alternate customer login route |

**Previous mistake:** `/#/login/requester` is **not** a valid SuperOps route - it falls through to the chooser. Correct path is **`/#/requester/login`**.

Configure via `SUPEROPS_REQUESTER_LOGIN_PATH=/#/requester/login` (default).

**Do not test requester SSO with `tom.ashby@onit.ltd`.** Tom is an MSP technician in SuperOps. Even if the URL contains `requester`, SuperOps will authenticate his Microsoft account and land him in the **technician** console. That is expected - not a portal bug.

| Button | Who | On IT Portal equivalent |
|---|---|---|
| **Login as requester** | Client/end-user - view and raise tickets for their organisation | `client_requester`, `client_billing_admin`, `client_admin` |
| **Login as technician** | MSP staff - manage all clients, RMM, PSA | On IT internal staff (not portal clients) |

**Portal users:** clients use **requester**; On IT technicians use **technician** launch from the same dashboard tile (role-based routing, same pattern as Pax8).

**Testing requester SSO:** Use `portal.test@onit.ltd` (`client_requester` on On IT Technology Partners). Do **not** use `tom.ashby@onit.ltd` for requester validation - Tom is an MSP technician in SuperOps.

**Testing technician launch:** Use `tom.ashby@onit.ltd` (`super_admin`) - portal redirects to `portal.onit.ltd/#/technician/login` (same host as requester, different path).

### Minimum to open SuperOps (manual login on their page)

1. Find your subdomain in SuperOps → **Settings → My Company → Branding** (subdomain name).
2. Add to `.env`: `SUPEROPS_SUBDOMAIN=your-subdomain` (On IT example: `onitltd` → `https://onitltd.superops.ai`).
3. Run `php artisan config:clear`.
4. Click **SuperOps** on the dashboard - you should land on `https://your-subdomain.superops.ai`.

### Requester SSO setup (step-by-step)

**→ [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md)** - Entra + SuperOps guide for **client requesters**.

**→ [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md)** - Entra + SuperOps guide for **On IT technicians** (separate SAML app #3).

Summary: three Entra apps (portal OAuth + requester SAML + technician SAML). Entra Login URLs live in SuperOps admin only; portal redirects to `portal.onit.ltd` with role-specific SPA paths.

## createTicket contract (all clients)

Portal **Log a ticket** and **New starter** both call `SuperOpsTicketService::createTicket`. That is **one MSP GraphQL mutation** for every SuperOps-linked organisation. There is no per-customer payload and no customer-specific workaround.

**Do not** diagnose or fix create failures by probing a named client on production. Change `SuperOpsTicketService` / `SuperOpsApiClient`, cover with `Http::fake` tests, then deploy.

### Required `CreateTicketInput` (On IT MSP)

Vendor docs mark several of these optional. **This MSP rejects the mutation without them** (HTTP 200 + `extensions.clientError`, often with an empty GraphQL `message`).

| Field | Value | Notes |
|---|---|---|
| `subject` | string | Required by schema |
| `description` | HTML string | SuperOps renders HTML; plain `\n` is collapsed. Portal sends `nl2br(e())` for Log a ticket, and a field list for New starter. Do not use `Name <email>` - SuperOps treats it as a tag. |
| `client.accountId` | `clients.superops_account_id` | Client must be linked |
| `source` | `INTEGRATION` | SuperOps `TicketSource` enum: `FORM` \| `AGENT` \| `EMAIL` \| `AI` \| `PHONE` \| `INTEGRATION`. **`PORTAL` is not valid** and returns GraphQL Internal Server Error |
| `subSource` | `On IT Portal` | Identifies the portal as the integration |
| `status` | `Open` | |
| `requestType` | `Incident` (`SUPEROPS_DEFAULT_REQUEST_TYPE`) | **Mandatory on this MSP.** Omission → `mandatory_validation_failed` on `requestType` |
| `requester.userId` | `users.superops_user_id` | Included when the portal user is linked |
| `requester.email` | portal email | Included when `superops_user_id` is missing |

### Symptom (any client)

- UI: "Unable to submit the new starter request..." or "Unable to submit support request..."
- SuperOps HTTP 200 with either:
  - `errors` + `Internal Server Error` (`source: PORTAL`), or
  - `extensions.clientError` `mandatory_validation_failed` / `attributes: ["requestType"]` (empty `message` is common)
- Laravel used to log `SuperOps API error: ` with a blank message because it only read `errors[].message`

`SuperOpsApiClient` must treat `extensions.clientError` as failure even when GraphQL `message` is empty. `createTicket` must throw if SuperOps does not return `ticketId` (do not flash success with no ticket).

Override request type only if SuperOps renames types: `SUPEROPS_DEFAULT_REQUEST_TYPE` in `.env`.

## Ticket detail (`getTicket`)

After create, the portal redirects to `GET /support/{ticketId}` (internal SuperOps `ticketId`, not the human `#displayId`).

The SuperOps **Ticket** type has **no `description` field**. Selecting it returns a GraphQL error. `SupportController@show` used to catch that and flash **Ticket not found** while the list still showed the new ticket.

Correct show query: `ticketId displayId subject status priority createdTime updatedTime requester` (leaf JSON). Opening body from `getTicketConversationList` (`content`). If conversation fetch fails, still render subject/status.

`SupportController@show` used to 403 unless SuperOps `requester.userId` / email matched the portal user. Portal **Log a ticket** / **New starter** use `source: INTEGRATION`; SuperOps often stores a different requester (or none) even though the ticket is on the correct client. The ticket is in SuperOps; the confirmation page was Laravel `403 | FORBIDDEN`.

View rules now:

1. Requester userId (`userId` / `user_id` / `id`) or email still matches, or
2. The ticket was created via the portal (cache `superops-ticket-account:{ticketId}` for 14 days) and the viewer belongs to that SuperOps account (client-facing user on that client, or a technician who can access the client)

Do **not** select extra Ticket fields on `getTicket` without a test - `description` already broke show for every client.

When the portal user has no `superops_user_id`, create still sends `requester.email` so SuperOps can attach the person when userId is missing.

## Client mapping

| Column | Purpose |
|---|---|
| `clients.superops_account_id` | SuperOps account for ticket creation |
| `clients.superops_sso_enabled` | Enable SSO launch for client users |

## Portal link types

| `link_type` | Behaviour |
|---|---|
| `external` | Plain URL |
| `superops_embedded` | `/support` |
| `superops_sso` | SSO launch route |

## Code files

- `app/Services/SuperOps/SuperOpsApiClient.php`
- `app/Services/SuperOps/SuperOpsTicketService.php`
- `app/Services/SuperOps/SuperOpsUserSyncService.php`
- `app/Services/SuperOps/SuperOpsSsoService.php`
- `app/Http/Controllers/SupportController.php`
- `app/Http/Controllers/ContactSupportController.php`
- `app/Http/Controllers/Integrations/SuperOpsLaunchController.php`

## SuperOps admin setup

1. **Settings → My Company → Branding** - note subdomain → `SUPEROPS_SUBDOMAIN`
2. **Settings → Your Profile → API Token** - generate → `SUPEROPS_API_TOKEN` (for `/support` + user sync)
3. **Requester SSO** - follow [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) → set `SUPEROPS_SSO_URL`
4. **On IT Portal Admin → Clients** - map `superops_account_id`, enable `superops_sso_enabled` per client

## Expected user experience (target)

```
On IT Portal → Sign in with Microsoft (once)
            → Dashboard
            → SuperOps (one click)
            → `/#/requester/login` → Microsoft SAML → requester dashboard
```

The browser already has a Microsoft session from the portal. SuperOps starts **SAML** to Entra at `/#/requester/login`; Entra may recognise the session and return the user without prompting again.

**Access control:**

| Role | Tile visible when | Launch destination |
|---|---|---|
| `super_admin`, `account_manager` | `SUPEROPS_SUBDOMAIN` or requester URL set | Same SuperOps host as requester (`portal.onit.ltd`) + `/#/technician/login` |
| `client_requester`, `client_billing_admin`, `client_admin` | `superops_sso_enabled` on client | Requester portal `/#/requester/login` |

**The portal cannot pass its Laravel session to SuperOps.** Seamless login uses the **same Microsoft account** already signed into the portal:

| Audience | What happens |
|---|---|
| **Technicians** | Portal OAuth → M365 session → launch `portal.onit.ltd/#/technician/login` + `login_hint` → SuperOps **Technician Login SSO** (SAML Entra app #3) → MSP console. Requires [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md). |
| **Client users** | Launch `portal.onit.ltd/#/requester/login` → customer's SuperOps **Client SSO** → customer's Entra SAML app → requester dashboard. |

**Do not launch technicians to `app.superops.ai`** - that host does not use the custom-domain Technician Login SSO configuration. Use the same host as requesters (`portal.onit.ltd`) with `/#/technician/login`.

## When it breaks

| Screen | Meaning |
|---|---|
| Role chooser (requester vs technician) | Wrong launch path - portal must use `/#/requester/login`, not `/#/login` or `/#/login/requester` |
| SuperOps card missing for admin | Set `SUPEROPS_SUBDOMAIN` or `SUPEROPS_REQUESTER_PORTAL_URL` in `.env`; run `php artisan config:clear` |
| SuperOps card missing for client | Client needs `superops_sso_enabled`; user must be `client_requester`, `client_billing_admin`, or `client_admin` |
| **Error 1027** | Entra `email` claim missing - see [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) §2.4 |
| **Error 1028** on Entra Test SSO | Ignore - use portal or `/#/requester/login` flow (SP-initiated) |
| New starter / Log a ticket fails for any client | Check createTicket contract: `source` INTEGRATION, `requestType` set; logs should include SuperOps `clientError` - [createTicket contract](#createticket-contract-all-clients) |
| Ticket created but **403 Forbidden** on `/support/{id}` | INTEGRATION requester often does not match the portal user; show allows the creating org via `superops-ticket-account` cache - [ticket detail](#ticket-detail-getticket) |
| Ticket created but **Ticket not found** on `/support/{id}` | Do not select `description` on `getTicket` - [ticket detail](#ticket-detail-getticket) |
| **Login with Email** on requester login | Client SSO is not enabled for that SuperOps client |
| **Access denied** on `/#/client-home` | SAML OK - fix SuperOps requester provisioning / permissions |

If you see an email form, `SUPEROPS_SSO_URL` / portal `.env` is not the fix. Verify that customer's Client SSO association, Azure Login URL and certificate.

### SuperOps admin checklist (fix “Login with Email”)

1. **Settings → Requester Login** → **SSO Protected** tab (not Password Protected)
2. **Client SSO** → open the customer's configuration
3. Confirm **Login URL**, **Certificate** and selected client match the customer's Entra app (see [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md))
4. Requester user **invitation complete** (pending invites sometimes use email flow first)
5. Test in private window: open via portal → **SuperOps** - should redirect to **Microsoft**, not email form
6. If still email → open ticket with SuperOps support (SSO not applied to requester login)

### What the portal controls

| Portal action | Result |
|---|---|
| `GET /integrations/superops/launch` | Technicians → `/#/technician/login`; clients → `/#/requester/login` with `login_hint` |
| Role-based access | Admins need `SUPEROPS_SUBDOMAIN` or requester URL; clients need `superops_sso_enabled` |
| Stored `microsoft_tokens` on user | For future API/embed - **does not** log user into full SuperOps web UI |
| `SUPEROPS_API_TOKEN` + `/support` | Embedded tickets in portal without opening SuperOps (alternative UX) |

## Limitations

- No public SuperOps “create session” API - full portal SSO needs Entra + SuperOps Requester SSO
- `portal.onit.ltd` is the **SuperOps** requester portal - the Laravel app is **`app.onit.ltd`** - see [Deployment.md](Deployment.md)
- Pax8 SSO launch implemented - see [Pax8Integration.md](Pax8Integration.md). Pax8 API remains Phase 3.
