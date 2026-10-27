# Mail domains and DNS health (onboarding)

**Status:** Phase A + Phase B **live** (`2d34082`, 2026-10-27). Phase C and after remain queued until asked.  
**Audience:** Staff technicians onboarding customers in On IT Portal.  
**Customer UI:** Staff only through Phase B. Later: Client Admin may see (not requesters).

Portal is the main onboarding guide. Every new customer needs a **main domain** at create time. DNS health (MX / SPF / DKIM / DMARC) is checked in-app so mail readiness is not a separate spreadsheet.

## Locked decisions (2026-10-19)

| Topic | Decision |
|---|---|
| Create Client | **Name and main domain are both required.** No client without both. |
| Extra domains | Primary only on create. Add further domains on **Edit**. |
| Who sees it (now) | **Staff only** (`super_admin` / `account_manager`). |
| Who sees it (later) | **Client Admin** may get a read-only view. Not requesters. |
| DMARC / DNS bands | **Green** = present and industry-correct. **Yellow** = present but weak or wrong. **Red** = not present. |
| DMARC examples | Missing → red. `p=none` → yellow (set, not enforcing). `p=quarantine` or `p=reject` with a valid record → green. |
| DKIM | Do it properly: broad selector list (M365 `selector1`/`selector2` plus common gateways) **and** per-domain custom selectors. |
| Hand-off (step 12) | **Warn** if any check is still red. Do **not** hard-block hand-off (technician can finish with eyes open). |
| Priority | This programme is **NEXT_TASK**. Pax8 deeper waits. |

## Traffic lights (all checks)

| Band | Meaning |
|---|---|
| Green | Record exists and matches a correct / industry-standard shape |
| Yellow | Record exists but is weak, conflicting, or incomplete |
| Red | Expected record is missing |

Overall domain status = worst of MX, SPF, DKIM, DMARC.

## Phases

### Phase A - Needed for the six customers (build first)

1. `clients.primary_domain` + `client_domains` table (primary row created on create).
2. Create Client: required **Main domain** next to name; normalise (`ClientCo.co.uk` → `clientco.co.uk`).
3. Edit Client: domains panel - list, add/remove non-primary, set primary, custom DKIM selectors, **Check DNS now**.
4. DNS engine (public lookups from the app server): MX, SPF, DMARC, DKIM (known + custom selectors).
5. Checklist step early in Client setup Guide: mail domains + DNS. Done when primary exists and last check has **no red** (yellow allowed), or technician ticks deferred with note.
6. Hand-off step shows a warning banner if any domain still has red.
7. Staff-only. Tests + Brain.

### Phase B - After the six are moving (implemented)

1. After Connect Microsoft / bootstrap, queue `SyncClientEntraMailDomainsJob`. Edit Client → **Import from Microsoft**.
2. Prefer Graph **verified domains** (`GET /domains`). If `Domain.Read.All` is missing, fall back to **mailbox email suffixes** from `User.Read.All` (answers: yes, we can see the main domain from tenant mailboxes).
3. Seed empty `primary_domain` from default verified custom domain, else most common mailbox domain. Import other verified custom domains as rows (`source=entra` / `entra_mail`).
4. Cross-check on Edit: Entra-only vs portal-only.
5. Nightly `portal:recheck-client-domain-dns` at 04:10.
6. Integration Health notices for missing primary / still-red DNS.
7. Optional: add **Domain.Read.All** Application permission + Grant + customer re-consent for the full verified-domain list (mailbox fallback works without it).

### Phase C - Hardening

1. Provider profiles (M365 vs Google vs Mimecast / other relay).
2. History of check results (who ran, when).
3. Optional export for customer pack (CSV).

### After C (backlog, not started)

1. Client Admin read-only mail health on home/Reports (staff remain source of truth for fixes).
2. Autodiscover / MTA-STS / TLS-RPT / BIMI if ops ask.
3. Pax8 deeper and the rest of the former near-term backlog resume after this programme.

## Data model

**`clients.primary_domain`** - string, normalised, required for new clients.

**`client_domains`**

| Column | Purpose |
|---|---|
| `client_id` | Owner |
| `domain` | Unique per client |
| `is_primary` | Exactly one primary |
| `dkim_selectors` | JSON list of extra selectors to try |
| `source` | `manual` / `entra` / `entra_mail` |
| `last_checked_at` | Last DNS run |
| `last_result` | JSON: per-check band, detail, selectors found |
| `notes` | Optional technician note |

## DNS engine (Phase A)

| Check | Lookup | Green | Yellow | Red |
|---|---|---|---|---|
| MX | MX | ≥1 MX | Unusual / only null MX | No MX |
| SPF | TXT apex | One `v=spf1` with `~all` or `-all` | Multiple SPF, or `+all`, or broken | No SPF |
| DMARC | TXT `_dmarc.{domain}` | `v=DMARC1` with `p=quarantine` or `p=reject` | `p=none` or odd policy | No DMARC |
| DKIM | TXT `{selector}._domainkey.{domain}` (+ CNAME follow) | ≥1 valid public key | Selector present but unusable | No selector found |

**DKIM selector list (v1):** `selector1`, `selector2` (M365), `s1`, `s2`, `s3`, `google`, `default`, `dkim`, `k1`, `k2`, `k3`, `cm`, `sm`, `mimecast`, `s1024`, `s2048`, `mailgun`, `mg`, `sendgrid`, `smtpapi`, `everlytick`, `zendesk1`, `zendesk2`, `amazonses`, plus any custom selectors on the domain row.

## Explicit non-goals

- Portal does not change DNS at the registrar.
- Does not replace DomainEmailChange (user primary SMTP cutover).
- Does not require `p=reject` for checklist Done (quarantine is enough for green).
- Customer requesters never see this panel in A-C.

## Code map

| Piece | Path |
|---|---|
| Normalise / validate domain | `App\Support\MailDomain` |
| DNS checks | `App\Services\MailDns\MailDnsHealthService` |
| Entra import / seed / cross-check | `App\Services\MailDns\EntraMailDomainSyncService` |
| Graph domains + mailbox suffixes | `MicrosoftGraphClient::listTenantDomains`, `listMailDomainsFromUsers` |
| After Connect | `SyncClientEntraMailDomainsJob` from `BootstrapClientEntraJob` |
| Nightly DNS | `portal:recheck-client-domain-dns` |
| Bulk backfill | `portal:sync-entra-mail-domains --empty-primary-only` |
| Checklist | `ClientOnboardingService` step `mail_domains_dns` |
| Create / Edit UI | `admin/clients/create`, `_mail-domains` (Import from Microsoft) |
| Docs | This file; TechnicianTenantOnboarding; ClientOnboarding |

## Changelog

| Date | Note |
|---|---|
| 2026-10-27 | Phase B: Entra verified domains + mailbox-suffix fallback, seed empty primary, nightly recheck, IH notices. |
| 2026-10-19 | Programme approved: A-C + after. Decisions locked. Replaces Pax8 as NEXT_TASK. |
