# On IT Portal — Product Requirements

## MVP Scope

### In Scope

| Feature | Description |
|---|---|
| Microsoft Entra ID login | Multi-tenant OIDC/OAuth2 |
| SuperOps integration | Embedded `/support` + SSO launch |
| Client dashboard | Service cards, notices, recommendations, opportunities |
| External links | Pax8, M365, KB, Billing (launch-only) |
| Admin CRUD | Clients, users, links, content, settings, activity logs |
| Multi-tenant isolation | Strict `client_id` scoping |
| RBAC | super_admin, account_manager, client_requester, client_billing_admin, client_admin |

### Out of Scope

- Pax8 API integration
- Full bi-directional SuperOps sync
- Self-registration
- Microsoft Graph profile enrichment
- Email notifications

## User Stories

### Client Requester

- Log in with Microsoft work account
- View dashboard with organisation content
- Use embedded support without second login
- Launch Pax8/M365 and other external services
- No cross-tenant data visibility

### Super Admin

- Manage all clients, users, content, global links, settings
- Map clients to SuperOps accounts
- View all activity logs

### Account Manager

- Manage content and links for assigned clients only
- View users and logs for assigned clients

## Functional Requirements

### Authentication (FR-AUTH)

- FR-AUTH-01: Multi-tenant Entra via `organizations` endpoint
- FR-AUTH-02: Pre-provisioned users only
- FR-AUTH-03: Update `entra_object_id`, name, `last_login_at`, encrypted tokens on login
- FR-AUTH-04: Deny inactive users
- FR-AUTH-05: Case-insensitive email match
- FR-AUTH-06: Sync SuperOps requester ID on login when API configured

### SuperOps (FR-SOPS)

- FR-SOPS-01: List/create/view tickets scoped to requester
- FR-SOPS-02: SSO launch to full SuperOps portal
- FR-SOPS-03: Client `superops_account_id` for ticket creation

### Dashboard (FR-DASH)

- FR-DASH-01: Welcome with user and client name
- FR-DASH-02: Service cards via `resolved_url`
- FR-DASH-03: Latest 5 active notices
- FR-DASH-04–06: Recommendations, opportunities, responsive layout

### Portal Links (FR-LINK)

- FR-LINK-01: CRUD with name, type, URL, icon, order
- FR-LINK-02: Global or client-specific
- FR-LINK-03: `required_role` and `open_in_new_tab`
- FR-LINK-04: Types: `external`, `superops_embedded`, `superops_sso`

### Administration (FR-ADMIN)

- FR-ADMIN-01: `/admin` for super_admin and account_manager
- FR-ADMIN-02: User CRUD with role and client assignment
- FR-ADMIN-03: Activity logs read-only, paginated
- FR-ADMIN-04: Settings key/value

## Non-Functional Requirements

- NFR-01: 50 orgs / 50 concurrent users
- NFR-02: Database sessions in production
- NFR-03: Dashboard under 2s on standard connection
- NFR-04–07: Validation, CSRF, secrets in `.env`, Plesk deployable
