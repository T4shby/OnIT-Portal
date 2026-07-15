# On IT Portal — SuperOps Integration

## Overview

SuperOps is a **first-class integration**: embedded support in the portal plus SSO launch to the full requester portal. One Microsoft login covers the portal; support tickets load without a second sign-in.

## Architecture

```
Microsoft Entra ID → On IT Portal session
                     ├── /support (GraphQL API — embedded tickets)
                     └── /integrations/superops/launch (SSO to full portal)
```

### Pillar 1 — Embedded support

- Route: `GET /support`, `POST /support`, etc.
- `SuperOpsApiClient` → `https://api.superops.ai/msp` (or EU endpoint)
- Headers: `Authorization: Bearer <SUPEROPS_API_TOKEN>`, `CustomerSubDomain: <SUPEROPS_SUBDOMAIN>`, `Content-Type: application/json`
- Server-side `SUPEROPS_API_TOKEN` — never exposed to browser
- Tickets filtered by requester email / `users.superops_user_id`
- Client Admin organisation metrics: [ClientAdminDashboard.md](ClientAdminDashboard.md#superops-graphql-request-shape)

### Pillar 2 — SSO launch

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

# Requester Client SSO is configured in SuperOps per customer, not portal .env:
# SUPEROPS_SSO_URL=   ← leave empty
# Launch path (default skips role chooser):
SUPEROPS_REQUESTER_LOGIN_PATH=/#/requester/login
SUPEROPS_LOGIN_HINT_ENABLED=true

# Technician launch — same host as requester (defaults from SUPEROPS_REQUESTER_PORTAL_URL):
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_TECHNICIAN_LOGIN_PATH=/#/technician/login
# Optional override; legacy MSP app URL — not used for SSO launch:
# SUPEROPS_PORTAL_URL=https://app.superops.ai
```

### Role chooser (requester vs technician)

SuperOps SPA routes (from production bundle):

| Hash route | Purpose |
|---|---|
| `/#/login` | Requester **or** technician chooser |
| `/#/requester/login` | **Requester login directly** — portal uses this |
| `/#/technician/login` | Technician login |
| `/#/login/customer` | Alternate customer login route |

**Previous mistake:** `/#/login/requester` is **not** a valid SuperOps route — it falls through to the chooser. Correct path is **`/#/requester/login`**.

Configure via `SUPEROPS_REQUESTER_LOGIN_PATH=/#/requester/login` (default).

**Do not test requester SSO with `tom.ashby@onit.ltd`.** Tom is an MSP technician in SuperOps. Even if the URL contains `requester`, SuperOps will authenticate his Microsoft account and land him in the **technician** console. That is expected — not a portal bug.

| Button | Who | On IT Portal equivalent |
|---|---|---|
| **Login as requester** | Client/end-user — view and raise tickets for their organisation | `client_requester`, `client_billing_admin`, `client_admin` |
| **Login as technician** | MSP staff — manage all clients, RMM, PSA | On IT internal staff (not portal clients) |

**Portal users:** clients use **requester**; On IT technicians use **technician** launch from the same dashboard tile (role-based routing, same pattern as Pax8).

**Testing requester SSO:** Use `portal.test@onit.ltd` (`client_requester` on On IT Technology Partners). Do **not** use `tom.ashby@onit.ltd` for requester validation — Tom is an MSP technician in SuperOps.

**Testing technician launch:** Use `tom.ashby@onit.ltd` (`super_admin`) — portal redirects to `portal.onit.ltd/#/technician/login` (same host as requester, different path).

### Minimum to open SuperOps (manual login on their page)

1. Find your subdomain in SuperOps → **Settings → My Company → Branding** (subdomain name).
2. Add to `.env`: `SUPEROPS_SUBDOMAIN=your-subdomain` (On IT example: `onitltd` → `https://onitltd.superops.ai`).
3. Run `php artisan config:clear`.
4. Click **SuperOps** on the dashboard — you should land on `https://your-subdomain.superops.ai`.

### Requester SSO setup (step-by-step)

**→ [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md)** — Entra + SuperOps guide for **client requesters**.

**→ [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md)** — Entra + SuperOps guide for **On IT technicians** (separate SAML app #3).

Summary: three Entra apps (portal OAuth + requester SAML + technician SAML). Entra Login URLs live in SuperOps admin only; portal redirects to `portal.onit.ltd` with role-specific SPA paths.

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
- `app/Http/Controllers/Integrations/SuperOpsLaunchController.php`

## SuperOps admin setup

1. **Settings → My Company → Branding** — note subdomain → `SUPEROPS_SUBDOMAIN`
2. **Settings → Your Profile → API Token** — generate → `SUPEROPS_API_TOKEN` (for `/support` + user sync)
3. **Requester SSO** — follow [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) → set `SUPEROPS_SSO_URL`
4. **On IT Portal Admin → Clients** — map `superops_account_id`, enable `superops_sso_enabled` per client

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

**Do not launch technicians to `app.superops.ai`** — that host does not use the custom-domain Technician Login SSO configuration. Use the same host as requesters (`portal.onit.ltd`) with `/#/technician/login`.

## When it breaks

| Screen | Meaning |
|---|---|
| Role chooser (requester vs technician) | Wrong launch path — portal must use `/#/requester/login`, not `/#/login` or `/#/login/requester` |
| SuperOps card missing for admin | Set `SUPEROPS_SUBDOMAIN` or `SUPEROPS_REQUESTER_PORTAL_URL` in `.env`; run `php artisan config:clear` |
| SuperOps card missing for client | Client needs `superops_sso_enabled`; user must be `client_requester`, `client_billing_admin`, or `client_admin` |
| **Error 1027** | Entra `email` claim missing — see [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) §2.4 |
| **Error 1028** on Entra Test SSO | Ignore — use portal or `/#/requester/login` flow (SP-initiated) |
| **Login with Email** on requester login | Client SSO is not enabled for that SuperOps client |
| **Access denied** on `/#/client-home` | SAML OK — fix SuperOps requester provisioning / permissions |

If you see an email form, `SUPEROPS_SSO_URL` / portal `.env` is not the fix. Verify that customer's Client SSO association, Azure Login URL and certificate.

### SuperOps admin checklist (fix “Login with Email”)

1. **Settings → Requester Login** → **SSO Protected** tab (not Password Protected)
2. **Client SSO** → open the customer's configuration
3. Confirm **Login URL**, **Certificate** and selected client match the customer's Entra app (see [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md))
4. Requester user **invitation complete** (pending invites sometimes use email flow first)
5. Test in private window: open via portal → **SuperOps** — should redirect to **Microsoft**, not email form
6. If still email → open ticket with SuperOps support (SSO not applied to requester login)

### What the portal controls

| Portal action | Result |
|---|---|
| `GET /integrations/superops/launch` | Technicians → `/#/technician/login`; clients → `/#/requester/login` with `login_hint` |
| Role-based access | Admins need `SUPEROPS_SUBDOMAIN` or requester URL; clients need `superops_sso_enabled` |
| Stored `microsoft_tokens` on user | For future API/embed — **does not** log user into full SuperOps web UI |
| `SUPEROPS_API_TOKEN` + `/support` | Embedded tickets in portal without opening SuperOps (alternative UX) |

## Limitations

- No public SuperOps “create session” API — full portal SSO needs Entra + SuperOps Requester SSO
- `portal.onit.ltd` is the **SuperOps** requester portal — the Laravel app is **`app.onit.ltd`** — see [Deployment.md](Deployment.md)
- Pax8 SSO launch implemented — see [Pax8Integration.md](Pax8Integration.md). Pax8 API remains Phase 3.
