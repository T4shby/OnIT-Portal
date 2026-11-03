# Passkey / SMS-voice MFA retirement (customer readiness)

**Status:** Phase 1 inventory **live** for staff; Client Admin portal page added (deploy when asked).  
**Audience:** On IT technicians **and** customer Client Admins preparing for Microsoft's passkey default and SMS/voice MFA retirement.  
**Customer UI:** Client Admin full inventory + CSV (same data as staff). Not requesters / billing.

## Microsoft deadlines (locked context)

| Date | Change |
|---|---|
| From **1 September 2026** | Passkeys begin rolling out as the **default** authentication method. |
| **1 February 2027** | Native **SMS** and **voice** MFA delivery retired. |

Portal goal: each customer has a **documented view** of who still uses SMS/voice, who already has phishing-resistant / passkey-capable methods, and a clear follow-up (registration campaign / project).

## Locked product decisions (2026-10-27)

| Topic | Decision |
|---|---|
| Data source | Graph `reports/authenticationMethods/userRegistrationDetails` (already used for aggregate MFA %). Needs `AuditLog.Read.All` / `Reports.Read.All` + Entra ID P1/P2 for the report. |
| Per-user fields | Display name, UPN, MFA registered/capable, passwordless capable, methods registered, SMS/voice risk flag, passkey/Authenticator flags. |
| Who sees it (now) | **Staff:** Admin → Clients → Edit → **MFA methods / passkey readiness**. **Client Admin:** Services → **MFA methods** (`/microsoft-365/mfa-methods`) + Directory/MFA tabs on Microsoft 365 pages. Gate `view-mfa-methods` (Client Admin + M365 entitled). |
| Who does **not** | Requesters and billing admins - org-wide auth-method inventory stays Client Admin / staff. |
| Findings / plans | Phase 1 = live inventory + CSV. Later: per-client notes / recommended passkey method / campaign status stored in portal. |
| Priority | **NEXT_TASK** ahead of mail domains Phase C and Pax8 deeper. |

## Phases

### Phase 1 - Inventory (build first)

1. Pull full `userRegistrationDetails` (not only %).
2. Classify each member user: SMS/voice present, passkey present, Authenticator present, passwordless capable.
3. Staff page per client + CSV export for customer packs.
4. Soft-fail when Graph 403 / empty (same pattern as Secure Score / MFA %).
5. Tests + Brain.

### Phase 2 - Recommendations and findings

1. Suggested passkey path per customer (default: Microsoft Authenticator; note FIDO2 / Windows Hello where already present).
2. Technician notes + campaign status on the client record.
3. Integration Health or sold-coverage style notice when SMS/voice share stays high.

### Phase 1b - Client Admin portal (done in repo; deploy when asked)

1. Same inventory + refresh + CSV for Client Admin on `/microsoft-365/mfa-methods`.
2. Services nav item **MFA methods**; Directory ↔ MFA subnav on Microsoft 365 pages.
3. Requesters / billing remain blocked.

### Phase 3 - Campaign ops

1. Diff vs last refresh (who newly registered / still on SMS).
2. Export pack for QBR / project opportunity tracking.
3. Optional glance SMS-risk callout on Dashboard.

## Method classification (Phase 1)

| Flag | Graph signals (typical) |
|---|---|
| SMS / voice risk | `methodsRegistered` contains `mobilePhone`, `alternateMobilePhone`, `officePhone`; or system preferred `sms` / `voice*` |
| Passkey-capable | `passKeyDeviceBound`, `passKeyDeviceUnbound`, `fido2`, `windowsHelloForBusiness`, or `isPasswordlessCapable` |
| Authenticator | `microsoftAuthenticatorPush`, `microsoftAuthenticatorPasswordless` |

Exact Graph string sets are centralised in `App\Services\M365\MfaAuthenticationMethodsService`.

## Explicit non-goals (Phase 1)

- Portal does not enrol or remove auth methods.
- Does not change Conditional Access or authentication method policies.
- Does not invent licence entitlement - report soft-fails when Graph denies.

## Code map (Phase 1)

| Piece | Path |
|---|---|
| Graph list | `MicrosoftGraphClient::listMfaUserRegistrationDetails` |
| Classify + cache | `App\Services\M365\MfaAuthenticationMethodsService` |
| Refresh job | `RefreshMfaAuthMethodsJob` |
| Staff UI + CSV | `ClientMfaMethodsController`, `admin/clients/mfa-methods` |
| Client Admin UI + CSV | `Microsoft365MfaMethodsController`, `microsoft-365/mfa-methods` (shared `_mfa-methods-body`) |
| Gate | `view-mfa-methods` in `AuthServiceProvider` |
| Aggregate % (unchanged) | M365 insights Secure Score / MFA tile |

## Changelog

| Date | Note |
|---|---|
| 2026-11-02 | Client Admin can view / refresh / export MFA methods on the portal (not requesters). |
| 2026-10-27 | Programme approved; Phase 1 inventory built. |
