# Contact Support (client Service Desk hub)

Customer-facing **Contact Support** hub for everyone except On IT technicians (`super_admin` / `account_manager`).

## What it is

| Item | Detail |
|------|--------|
| Nav | Desktop + mobile More: **Contact Support** next to Services. Mobile bottom tab: **Contact**. Hub uses the same wide content width as Microsoft 365 / Support & Devices (`max-w-[96rem]`). |
| URL | `/contact-support` (`contact-support.index`) |
| Gate | `contact-support` - client-facing role + `client_id` |
| Audience | `client_admin`, `client_billing_admin`, `client_requester` (and legacy `client_user`) |

Technicians use Staff Admin and SuperOps; they do **not** see this nav item.

## Hub cards

1. **Log a ticket online** → existing `/support/create` (SuperOps API). Also link to ticket list.
2. **Phone & hours** - phone, hours, email, address from `config/onit_support.php` (overridable via `.env`).
3. **New starter** → custom portal form that creates a SuperOps ticket via API (not a SuperOps hosted form).

## Defaults (live)

| Field | Value |
|-------|--------|
| Phone | 03300 945 946 |
| Email | service.desk@onit.ltd |
| Hours | Monday-Friday, 09:00-17:00 (UK) |
| Address | Unit G, Wheatley Park · Mirfield · WF14 8HE |

Env keys: `ONIT_SUPPORT_PHONE`, `ONIT_SUPPORT_EMAIL`, `ONIT_SUPPORT_HOURS`, `ONIT_SUPPORT_HOURS_TZ`, `ONIT_SUPPORT_ADDRESS` (pipe-separated lines).

## New starter → SuperOps

- Form: `contact-support.new-starter` / `store`
- Service: `App\Services\Support\NewStarterTicketService`
- Subject: `New starter request: {name}`
- Description: structured text (requester, org, starter fields, equipment, notes)
- Uses **the same** `SuperOpsTicketService::createTicket` as **Log a ticket** (every SuperOps-linked client, one MSP payload)
- Requires SuperOps API configured + client `superops_account_id`
- Contract, required fields, and 2026-08-24 outage notes: [SuperOpsIntegration.md - createTicket contract](SuperOpsIntegration.md#createticket-contract-all-clients)

## Code map

| Piece | Path |
|-------|------|
| Controller | `app/Http/Controllers/ContactSupportController.php` |
| Config | `config/onit_support.php` |
| Views | `resources/views/contact-support/{index,new-starter}.blade.php` |
| Gate | `AuthServiceProvider` → `contact-support` |

## Changelog

| Date | Note |
|------|------|
| 2026-08-24 | Document + harden MSP-wide createTicket (`INTEGRATION` + `requestType: Incident`); GraphQL client now surfaces SuperOps `clientError`. |
| 2026-08-24 | Contact Support hub uses the wide desktop content width (same as M365 / Support & Devices). |
| 2026-08-24 | Hub + nav + custom new starter → SuperOps ticket; contact details from ops |
