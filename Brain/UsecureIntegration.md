# usecure (Human Risk) - integration plan

**Status (2026-09-30): API exists. Not implemented. Next step is a partner key, not code.**

## Next steps

Do these in order. Stop at step 4 until the vault has a key. Do not start portal code before that.

| Step | Who | Done when |
|------|-----|-----------|
| 1 | On IT technician | Logged into **uService** (the partner portal for usecure clients). |
| 2 | On IT technician | **Settings > API** shows a new key, and the region is written down: **US**, **EU**, or **EU Legacy**. If that screen is missing, send the support request in the section below and wait for a key plus a URL ending in `/graphql`. |
| 3 | On IT technician | One pilot company id is copied from the usecure company list (a client that already buys usecure). |
| 4 | On IT technician | Key, region or URL, and the pilot company id are in the team password vault only. Not in git, not in chat, not in `.env` yet. |
| 5 | Engineer | A throwaway probe under `tmp/` calls the API, lists companies, and pastes field names into this file. The probe is deleted after. |
| 6 | Engineer | Phase 1 ships behind `USECURE_ENABLED=false`: company id on the client, metrics job, home tile, Integration Health column. |
| 7 | On IT technician | The pilot client is marked sold and mapped. Integration Health moves from Never loaded to Up to date. |
| 8 | On IT technician | `USECURE_ENABLED=true` on production only after that pilot cell is green. Other buyers are mapped the same way. Non-buyers see no usecure data. |

v1 is read-only: risk score, training completion, overdue training, phish results, open breach alerts. No enrol, no policy send, no internal notes.

Product: [usecure.io](https://usecure.io/) - MSP human risk (training, phishing, risk scoring).  
Partner multi-tenant admin: **uService** (all On IT customers under the On IT partner tenant - same idea as Huntress).

---

## Product model (how On IT sells it)

| Rule | Behaviour in portal (when built) |
|------|----------------------------------|
| On IT sells usecure to **some** clients only | `product_entitlements.usecure.entitled` true/false |
| All customer companies live under **On IT’s** usecure partner tenant | One MSP API key + one GraphQL base URL in `.env` |
| Per customer identity in usecure | Mapping column on `clients` (planned name below) - not a second partner login per client |
| Buyers get the same experience as other sold products | System health tile + cache/prewarm + Integration Health column |
| Non-buyers | No reports/tiles (requesters); Client Admin may see not-sold upsell copy only |
| Staff | Set sold + map company id; Integration Health like Huntress |

**Do not** use Gradient Reconcile unless Ops already relies on Gradient. Gradient only documents how *their* product consumes the same beta API ([Gradient uSecure guide](https://support.meetgradient.com/usecure)). The portal will call GraphQL **directly** (Huntress-style partner credentials).

---

## Getting API access (ops - before any code enablement)

Two partner guides describe access. They do not match each other, so ops should try the portal screen first and fall back to Support.

- **Junto** (updated guide): log into the usecure admin portal, **Settings > API**, generate a key, and record the region (**US**, **EU**, or **EU Legacy**). The key can list companies and read learners, training, phishing, breaches, policies, and risk scores. Some write actions exist (enrol learners, send policies). The portal integration stays **read-only**.
- **Gradient** (older guide): API is **beta**. Contact usecure Support for an API key and an API URL that ends in `/graphql`.

### Steps for On IT

1. Log into **uService** (MSP partner portal used to manage usecure clients day-to-day).
2. Look for **Settings > API**. If it is there, generate a key and note the region (US, EU, or EU Legacy). If it is not there, contact **usecure Support**:
   - In-app **chat** in uService, and/or
   - Support paths advertised on [usecure.io](https://usecure.io/).
3. Request something like:

   > On IT Technology Partners - MSP partner. Please enable **beta GraphQL API** for our partner tenant. We need: **API key**, **API URL** ending in `/graphql`, auth method (header name / body), rate limits, any schema or sample queries to **list companies** and **read per-company** risk / training / phishing stats (and user-level if available). Use case: read-only data in our own multi-tenant customer portal; we map companies to clients (like Huntress org ids).

4. Store key + URL in the team password vault only. Never commit. Later: production `.env` only.

### What ops should obtain from support

| Item | Why |
|------|-----|
| API key | Auth for all GraphQL calls under the partner |
| API URL (`…/graphql`) | Endpoint; must suffix `/graphql` per [Gradient](https://support.meetgradient.com/usecure) |
| Auth shape | Header (`Authorization: Bearer …` vs custom) - lock when sample response arrives |
| List companies query | Discovers mapping candidates under the On IT tenant |
| Company summary fields | Risk score, training %, phishing, breaches - lock DTOs |
| Optional: user-level fields | Only if requesters get personal tiles later |
| Console deep-link pattern | Optional “Open usecure” CTA |
| Rate limits | Prewarm budget |

### Evidence sources (how we know)

| Claim | Source |
|-------|--------|
| Need API Key + API URL; beta; contact usecure Support | [Gradient - uSecure](https://support.meetgradient.com/usecure) |
| URL must end with `/graphql` | Same page |
| Settings > API key, regions US / EU / EU Legacy, company and learner reads | [Junto - usecure Setup](https://docs.juntoai.com/integrations/usecure) (rechecked 2026-09-30) |
| uService is partner platform; support via chat | [usecure Partner Agreement](https://usecure.io/legal/partner-agreement) (uService + chat support) |
| Multi-tenant MSP platform / sold to some clients | Product positioning + On IT commercial model |
| Portal architecture (feeds, IH, entitlements) | This repo - clone Huntress/Dropsuite |

---

## Go / no-go

| Condition | Decision |
|-----------|----------|
| A partner key (Settings > API, or Support) lists companies | **Go** - implement Phase 1-2 below |
| API refused / delayed / no company filter | **Hold** - do not stub production code; keep this doc only |
| API is login redirect only (no data) | **No-go** for metrics feed; optional external link tile only |

---

## Planned architecture (mirror Huntress)

### Catalog / DB (planned)

| Piece | Planned value |
|-------|----------------|
| Product key | `usecure` |
| Entitlement JSON | `product_entitlements.usecure.entitled` |
| Mapping column | `clients.usecure_company_id` (name may change if vendor uses `account_id` / UUID - rename once sample payload exists) |
| Platform config | `config/services.php` → `services.usecure` |
| Env | `USECURE_API_URL`, `USECURE_API_KEY`, `USECURE_ENABLED=false` until pilot green |

### Code surfaces (build checklist - Dropsuite/Huntress pattern)

Implement in this order when unblocked. Full generic checklist: [ClientAdminDashboard.md - Feed checklist](ClientAdminDashboard.md#feed-checklist-add-a-new-vendor).

1. **Migration** - `usecure_company_id` on `clients`; `Client` fillable.
2. **Config** - `services.usecure` + `.env.example` stubs (no secrets).
3. **Entitlements** - `ClientProductService::KEY_USECURE`, `catalog()`, `isMapped`, `isPlatformReady`, `hasRecentFailure` prefix.
4. **API** - `App\Services\Usecure\UsecureApiClient` (GraphQL POST; partner token).
5. **Metrics** - `UsecureClientMetricsService`: `summaryForClient`, `queueRefresh`, `needsBackgroundRefresh`, `refreshAndStore`, **public `cacheKey($clientId)`** e.g. `client:{id}:usecure:v1`.
6. **Job** - `RefreshUsecureMetricsJob` on `high`, unique per client; flags `usecure_metrics.refresh_*` / `last_result`.
7. **Feed** - `UsecureDashboardFeed` (`key=usecure`, `prewarmPriority=optional`, overview partial).
8. **Register** - `AppServiceProvider` feed list; `PrewarmClientDashboardsCommand::isFeedCold` match; registry only needs a hard match if personal scoping is non-default.
9. **Admin UI** - `admin/clients/products/_usecure.blade.php`; validation on store/update client; products matrix via catalog.
10. **Client UI** - `client-admin/feeds/_usecure.blade.php` (org risk/training summary).
11. **Integration Health** - `FEED_COLUMNS`, `JOB_CLASS_HINT`, private `usecure()` builder in `clientRow()` using **only** `cacheKey()`.
12. **Visibility** - org-wide via `canViewOrganisationWide`; v1 **Client Admin + staff only** unless user-level API is confirmed.
13. **Probe** - extend `portal:probe-security-apis` or `portal:probe-usecure` with company id.
14. **Tests** - registry keys, metrics HTTP/GraphQL fake, IH not-sold/cold/live, product save feature.
15. **Brain** - mark this file **Shipped** + changelog; update Deployment env table.

**Hard rule:** Integration Health and prewarm cold never hardcode legacy cache key versions - always `app(UsecureClientMetricsService::class)->cacheKey($id)`.

### Suggested v1 tile KPIs (confirm against real schema)

Pick 3-5 stable numbers only (not a full usecure UI):

- Human risk score / band  
- Training completion %  
- Users overdue training  
- Phish fail rate (period)  
- Credential / breach alerts open (if available)

Detail inventory page: **out of scope for v1** unless schema is trivially listable.

### Visibility (v1)

| Audience | Access |
|----------|--------|
| Sold + Client Admin | Org summary tile |
| Sold + requester | Hide until personal data API is confirmed (prefer org-only first) |
| Not sold | No data; CA upsell optional |
| Technician | All sold mapped clients + staff tools |

---

## Implementation phases

### Phase 0 - Credentials & schema (ops + engineer probe)

- Request keys (above).
- Local probe only under `tmp/` (gitignored): one GraphQL call, document response shape into this file.
- Lock final mapping column name + `cacheKey` payload fields.

### Phase 1 - Platform skeleton (feature-flagged)

- Entitlements + map column + metrics job + feed + **Integration Health column**.
- `USECURE_ENABLED=false` on production until probe passes.
- Pilot: one sold client with known company id → cell progresses **Never loaded → Up to date**.

### Phase 2 - Paying customers

- System health partial + admin product form live.
- Prewarm optional + cold-first under queue pressure (existing prewarm policy).
- Sold coverage KPI automatically includes feed once IH builder exists.

### Phase 3 - Optional later

- Staff “View usecure” page  
- “Open usecure” console link  
- Requester personal training score  
- Alerts on risk spike (separate backlog product)

---

## Out of scope for first delivery

- Full uService UI parity in the portal  
- Customer self-service buy/upgrade  
- Gradient as middleman  
- Writing data back into usecure (reads only)

---

## Related Brain docs

| Doc | Role |
|-----|------|
| [ClientAdminDashboard.md](ClientAdminDashboard.md) | Feed checklist, IH, prewarm, entitlements |
| [Roadmap.md](Roadmap.md) | Listed under **Later / optional** (not near-term) |
| [ProductRequirements.md](ProductRequirements.md) | Product intent when unblocked |
| [DatabaseSchema.md](DatabaseSchema.md) | Planned column note |
| [Deployment.md](Deployment.md) | Env keys when shipping |

---

## Resume prompt (for future agent / engineer)

When keys exist:

1. Read this file + Dropsuite/Huntress implementations as templates.  
2. Probe GraphQL; paste sanitized field names into “Suggested v1 tile KPIs” section.  
3. Implement Phases 1-2 with `USECURE_ENABLED` off until pilot green.  
4. Mark **Status: Shipped** here and add README changelog row.

---

## Changelog

| Date | Note |
|------|------|
| 2026-09-30 | Next steps written: key and one company id first, then a probe, then a flagged pilot. Still no On IT key, so no portal code. |
| 2026-09-30 | Rechecked public partner docs. API exists (portal Settings > API, or Support-issued GraphQL key). Still no On IT key, so no portal code. |
| 2026-08-10 | Design-only: sales model (sold some clients), Huntress-like tenant, beta API access via support, full modular build checklist. **No code.** |
