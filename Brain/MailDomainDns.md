# Mail domains and DNS health (onboarding)

**Status:** Phase A + B + C **live**.  
**Audience:** Staff technicians onboarding customers in On IT Portal.  
**Customer UI:** Staff Edit Client for DNS. Client Admin MFA is separate.

Portal is the in-app **MXToolbox-style DNS tester** for onboarding - not a place to invent DNS. Every new customer needs a **main domain** at create time. Technicians Check DNS and read traffic lights so they know what to fix at the registrar.

## What technicians should care about

| Priority | Checks | Drives hand-off red? |
|---|---|---|
| **Core (always)** | **MX, SPF, DKIM, DMARC** (public DNS) | **Yes** - overall / checklist / “DNS still has red” |
| **M365 email auth (required when M365 / Entra)** | Defender DKIM **Enabled + Valid** + test email SPF/DKIM/DMARC pass | Blocks step 00 auto-complete (not DNS red) |
| Advisory (optional) | Autodiscover, MTA-STS, TLS-RPT, BIMI | **No** - collapsed under Advisory; yellow when missing is fine |

### Lesson learned (Rayner Smith / 2026-11)

Adding DKIM CNAMEs in DNS does **not** enable signing in Microsoft 365. Defender can show `NoDKIMKeys` and outbound headers `dkim=none` while selector1/selector2 CNAMEs resolve. Portal DNS green for DKIM only means the public key path exists.

**Onboarding fix:** For M365 / Entra-linked clients, step 00 also requires two ticks under Customer tools → Mail domains:

1. `mail_dkim_signing_enabled` - Defender DKIM Enabled, status Valid  
2. `mail_auth_test_passed` - real send test: SPF + DKIM + DMARC pass  

DNS alone no longer auto-completes step 00 on that path.

**You do not need Add domain** for the primary - Create / Save client owns that. **Add domain** / custom DKIM selectors are Advanced only (extra brands, unusual DKIM selector names). Check DNS already tries M365 `selector1`/`selector2` and common gateways.

### Later (backlog)

Graph Exchange `GET /exchange/v1.0/dkimSigningConfigs/{domain}` (`Enabled`, `Status`) could auto-fill the Defender tick when App permission / TCM access is available - not required for the checklist gap fix.

### Advisory records (plain English)

| Record | What it is |
|---|---|
| Autodiscover | Helps Outlook find the mailbox settings |
| MTA-STS | Policy so mail servers prefer encrypted delivery |
| TLS-RPT | Reports about TLS delivery problems |
| BIMI | Brand logo in some inboxes (needs solid DMARC) |

## Edit Client UX (2026-11-02)

| Action | Where |
|---|---|
| Set / change **main domain** | Top of Edit → field → **Save client** (creates `client_domains` primary row + **runs DNS check now**) |
| Unchecked domains | Show **PENDING** (not red). Red only after a real check fails. Hand-off / "DNS still has red" ignore pending. |
| **Add domain** / Check DNS | Same card; Add and Check DNS run the lookup **now** (not only queue). |
| **Import from Microsoft** | Mail domains card **below** Save form (own POST - not nested in Save) |

**Bug fixed:** Import / Add / Check were nested `<form>` tags inside Save client. Browsers ignore nested forms, so Add domain and Import looked broken. Mail domains now sit in a separate card under Save. Opening Edit also heals rows that have `primary_domain` but no `client_domains` row.

**Layout (2026-11-02 / rebalanced 2026-11-04):** Edit Client `admin-split` is work column + sticky setup guide on the right. Guide is ~28-38rem so step titles stay readable; form grids cap at `max-w-3xl` so paste fields do not stretch across ultra-wide leftover space. Guide chrome (how-to, Graph tools when tenant already linked) collapses behind `<details>` so only the open step detail is on screen. Identity (name + main domain) sits in a two-column grid with Save at the top of that card.

**Edit Client page order (2026-11-04):** Top full-width **Customer tools and checkers** (M365 / MFA / Huntress / Dropsuite / Entra sync + Mail DNS checker). Then **Identity and products** + sticky **Client setup guide**.

**Products (2026-11-06):** Portal products render as a **2-column condensed grid** (SuperOps, Microsoft 365, Huntress, Dropsuite), not full-width stacks. Pax8 sits in the same 2-up grid under Licence vendor. Paste IDs stay in the card.

**Mail DNS checker:** Table shows **domain + overall + MX/SPF/DKIM/DMARC + Check DNS**. Advisory cell and **Recent DNS checks** / **Advanced - Add domain / custom DKIM** are collapsed until opened. Hand-off / checklist still use **core overall only**.

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

### Phase C - Hardening (implemented in repo)

1. Provider profiles from MX (M365 / Google / Mimecast / Proofpoint / Barracuda / Mailgun / SendGrid / other).
2. History table `client_domain_dns_checks` (who, when, source, overall, full result JSON). Shown on Edit Client.
3. Export CSV on Edit Client → Mail domains.
4. Extended DNS checks (advisory yellow when missing - do **not** drive checklist red): Autodiscover, MTA-STS, TLS-RPT, BIMI.
5. `overall` = worst of core MX/SPF/DKIM/DMARC. `overall_extended` includes advisory checks.

### After C (backlog)

1. Client Admin read-only mail health on home/Reports (staff remain source of truth for fixes).
2. HTTPS fetch of MTA-STS policy file (DNS TXT only today).
3. Pax8 deeper and the rest of the former near-term backlog.

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
| Autodiscover | CNAME `autodiscover.` or SRV `_autodiscover._tcp.` | Present | Missing (advisory) | - |
| MTA-STS | TXT `_mta-sts.` with `v=STSv1` | Present | Missing (advisory) | - |
| TLS-RPT | TXT `_smtp._tls.` with `v=TLSRPT` | Present | Missing (advisory) | - |
| BIMI | TXT `default._bimi.` with `v=BIMI1` | Present | Missing (advisory) | - |

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
| History | `client_domain_dns_checks` / `ClientDomainDnsCheck` |
| CSV | `admin.clients.domains.export` |
| Checklist | `ClientOnboardingService` step `mail_domains_dns` |
| Create / Edit UI | `admin/clients/create`, `_mail-domains` (Import / Export / history) |
| Docs | This file; TechnicianTenantOnboarding; ClientOnboarding |

## Changelog

| Date | Note |
|---|---|
| 2026-11-06 | Email auth verification panel collapsed by default; solid `bg-onit-ink` + higher-contrast status colours. |
| 2026-11-06 | M365 email auth verification: DNS ≠ signing. Step 00 needs Defender DKIM Enabled + test email ticks (Rayner Smith gap). |
| 2026-11-04 | UX aligned: core four columns primary; advisory collapsed; Add domain / DKIM under Advanced. Product framing = DNS tester for onboarding. |
| 2026-11-03 | Phase C: provider profiles, check history, CSV export, Autodiscover/MTA-STS/TLS-RPT/BIMI (advisory). |
| 2026-11-02 | Edit layout: wide form + right guide (`admin-split` column order fixed). Mail domains card outside Save; heal primary without DNS row. |
| 2026-11-02 | Edit UX: Mail domains card outside Save form (nested forms broke Import/Add). Heal primary without DNS row on Edit. |
| 2026-10-27 | Phase B: Entra verified domains + mailbox-suffix fallback, seed empty primary, nightly recheck, IH notices. |
| 2026-10-19 | Programme approved: A-C + after. Decisions locked. Replaces Pax8 as NEXT_TASK. |
