# Handoff — Pre-Deployment Audit State (2026-09-29)

This file exists so a different AI agent (or a human) can pick this project up cold,
with zero prior context, and know exactly what's been done, what hasn't, and what to
do next. Read this first, then the docs it points to.

## What this project is

Laravel 11 monolith, MSP customer/staff portal ("On IT Portal"). Full stack details,
architecture, conventions, and hard rules are in `AGENTS.md` — **read that before
touching any code**, especially the "Security rules — do not casually change these"
section. It is kept current and is the source of truth for conventions.

## Did a full line-by-line review happen?

**Be precise about this — don't oversell it to anyone continuing the work:**

- **Pass 5** ("Fifth Audit Pass" in `docs/SYSTEM_AUDIT.md`) did a genuine close read of
  the entire `app/` tree (~146 files, ~25K lines), all 111 Blade views, all migrations,
  and config — file by file, not sampled. That pass found and fixed a critical
  unauthenticated vulnerability and 3 high-severity bugs.
- **Passes 6, 7, 8** were each targeted at specific findings from the prior pass (fix
  what was flagged) or a scoped regression-hunt of recently-changed files — not a fresh
  full re-read of the whole codebase each time.
- **Nothing has been verified against a real production environment.** Every fix has
  been checked with: (a) code review by the orchestrating session before merge, (b) the
  PHPUnit suite running against SQLite-in-memory. **Nobody has run this against real
  MySQL, the actual Plesk/nginx setup, or a real browser hitting the production
  domain.** The OAuth login-CSRF fix (pass 8) especially needs a real first login
  checked after deploy — see "Before you deploy" below.

So: **thoroughly audited and fixed, not battle-tested in production.** Treat anything
not explicitly called out as "verified in prod" as still needing that verification.

## What's been done (8 merged passes, chronological)

All merged into `main` via PRs #1–#9 (T4shby/OnIT-Portal). Full detail, evidence, and
file:line citations for every item below are in `docs/SYSTEM_AUDIT.md` — it has one
dated section per pass, in order. Read that file for the real detail; this is just an
index.

1. **Dependency patches, test fixes.** `composer audit`/`npm audit` findings patched
   (non-breaking), 7 stale tests fixed, initial audit docs created.
2. **Rate limiting, config drift, health check, log hygiene.** Added rate limit to
   support-ticket creation, fixed `.env.example` missing `SESSION_SECURE_COOKIE`,
   `/up` now actually checks DB connectivity, truncated an unbounded log field.
3. **Missing DB index, full route inventory, dead-code sweep.** All 91 routes read
   and cross-checked, no dead code found confidently enough to delete, one real index
   added.
4. **Two sync→queue conversions, dead middleware removed, frontend/infra/deps
   confirmed clean.**
5. **Full line-by-line review — the real deep pass.** 1 Critical + 3 High severity
   security fixes (see below), plus 9 more medium/low fixes. This is the pass that
   matters most if you only have time to read one section of `docs/SYSTEM_AUDIT.md`.
6. **Resolved 10 of 11 items** pass 5 had flagged for later (F1–F8, F10, F11).
7. **Feature retirement.** Notices/recommendations/opportunities feature removed
   entirely (was authored by staff, never shown to any customer — confirmed dead
   product surface, repo owner chose to retire rather than build a customer view).
8. **Pre-production re-audit.** Regression-hunted the files touched by passes 5–7
   specifically (where fix-on-fix bugs hide). Found and fixed 2 High + 4 Medium + 8
   Low **new** issues, including a queue-worker double-execution bug that would have
   caused real production failures, and a cross-client data leak via ID collision.
9. **Resolved the 3 remaining flagged items from pass 7**, including the highest-risk
   change in the whole audit: OAuth login-CSRF protection was disabled in production
   (a deliberate but insecure workaround for a session-loss bug) — replaced with a
   session-independent, cookie-based state check. See `AGENTS.md` rule 8 — **do not
   revert this or reintroduce a way to disable it.**

## Critical/High findings worth knowing about specifically

From pass 5 (all fixed, all merged):
- **Critical:** unauthenticated admin-consent callback could re-point any client to
  an attacker's Microsoft tenant. If this app was ever live before this fix, check
  logs/history for unexpected `entra_tenant_id` changes on any client.
- **High:** deactivated users kept access up to 400 days via remember-me cookie
  (session wasn't invalidated on deactivation).
- **High:** account managers with zero assigned clients saw *every* client's data
  (a fail-open scoping bug across 19 call sites).
- **High:** a login-time sync process could cross-link two unrelated customers'
  SuperOps data via a coincidental email match.

From pass 7 (all fixed, all merged):
- **High:** queue jobs could run twice concurrently (missing `retry_after` config
  vs. job timeouts up to 600s) — would corrupt in-flight state for the async SCIM/SSO
  flows.
- **High:** an account manager (or a copy-paste mistake by any admin) could point one
  client's external ID mapping (SuperOps/Huntress/Dropsuite/Pax8/Entra) at another
  client's ID and see that client's data through their own assigned client.

## Test suite

388 tests passing as of the last merge (`git log` on `main` for the exact commit).
Run `php artisan test`. There was a pre-existing test-order flake in
`ClientOnboardingServiceTest`/`HuntressClientMetricsServiceTest` early on — pass 6
fixed its root cause (a `ClientFactory` slug collision), hasn't recurred since.

## Before you deploy — do these

1. **Run the duplicate-external-ID check on production data.** Pass 8 added
   validation to stop *new* ID collisions between clients, but can't fix existing
   ones. The exact SQL query is in `docs/SYSTEM_AUDIT.md` under the pass 8 "H2"
   section — search for `whereRaw('LOWER(`.
2. **Run migrations.** The F9 pass (notices/recommendations/opportunities retirement)
   drops those tables permanently — confirmed with repo owner there's no data worth
   keeping. No other pass added migrations except pass 3 (one index).
3. **Run `php artisan config:cache`** after deploy (new `config/queue.php` from pass
   7 needs it).
4. **Delete `MICROSOFT_OAUTH_STATELESS` from production `.env`** if it's set — this
   variable no longer exists in code (pass 8 removed it entirely). Leaving it in
   `.env` is harmless (unused) but should be cleaned up.
5. **Sign in once after deploy and check `laravel.log`** for `Microsoft OAuth state
   check failed` — this is the one piece of pass 8's work that's genuinely unverified
   against real production infrastructure. Its `reason` field explains exactly what
   failed if it fires. If it does fire for real users, this is a code rollback, not a
   data rollback — no migration is tied to it.

## What's still open (deliberately not fixed, needs a human/product decision)

Full detail and reasoning for each is in `docs/SYSTEM_AUDIT.md` (search for
"flagged" / "Manual Review"). Short version:

- **Laravel 11 → 12/13 major upgrade** — 3 residual `composer audit` advisories only
  fixed by a major version bump (CRLF mail-header injection, signed-URL path
  confusion). Low practical risk: this app sends no outbound mail and uses no signed
  URLs. Deliberately not bundled into a dependency-patch pass; recommend a dedicated
  upgrade project.
- **Vite 5 → 8 major bump** — 1 remaining dev-server-only `npm audit` advisory
  (esbuild). Not worth a breaking config migration for a dev-only exposure.
- **No CI/CD pipeline** — tests and Pint aren't run automatically on push/PR. Worth
  setting up before this gets more contributors.
- **Pint code style not enforced** — no `pint.json` exists, so Laravel's default
  preset reports diffs across ~60 files that were never written to that preset.
  Don't blind-run `vendor/bin/pint` and commit the result (large unreviewed diff) —
  write a `pint.json` matching actual conventions first if you want to enforce style.
- **`socialiteproviders/microsoft-azure` dependency is ~2 years older** than
  everything else in `composer.lock`. It's the app's only login mechanism — worth
  checking upstream activity yourself before this matters more.
- **A few small UI inconsistencies** (one page hand-rolls polling logic instead of
  using the existing reusable component; two forms use vanilla JS instead of the
  Alpine pattern used elsewhere) — cosmetic, not bugs, not fixed.

## Where to go for more detail

- `AGENTS.md` — architecture, conventions, hard security rules. Read first.
- `docs/SYSTEM_AUDIT.md` — the full audit record, one dated section per pass, with
  file:line evidence for every finding. This is the primary source of truth for "what
  was actually checked and what was found."
- `docs/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/DEPLOYMENT.md` — current-state
  docs (not audit history), kept in sync with the code by every pass.
- `Brain/` — the team's own pre-existing knowledge base (product requirements,
  runbooks, per-integration setup notes). Referenced and lightly corrected by this
  audit where it had gone stale, never deleted.

## If you're a different AI agent continuing this work

- Don't re-derive what's already documented — read the files above first.
- Don't blindly trust a prior pass's "confirmed sound" without at least spot-checking
  if you're about to build on top of that area — passes 7 and 8 both found real
  regressions in areas an earlier pass had called fine, because later changes
  interacted badly with earlier ones. This is the single most valuable lesson from
  this whole audit: **re-verify, don't just cite.**
- The git workflow used throughout: branch off `main`, make focused commits, run
  `php artisan test`, open a PR, verify the diff against evidence before merging
  (not just trusting a report), merge, sync local `main`, repeat. Small commits, one
  logical change each.
