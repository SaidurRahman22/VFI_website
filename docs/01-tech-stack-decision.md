# 01 — ADR-001: Backend Stack for VFI Overseas Education

The architecture decision record for the backend stack: the forces, the choice, every alternative scored, and what would make us revisit it. For the tech lead and any engineer or agent implementing or maintaining the backend. Companion to the [Executive summary](00-executive-summary.md).

| | |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-08-09 |
| **Decision maker** | DevSecOps / Architecture Lead |
| **Supersedes** | the localStorage/IndexedDB browser-only demo (and the earlier NestJS/Prisma sketch in `memory.md`) |

---

## 1. Context and forces

VFI Overseas Education is a Dhaka study-abroad consultancy. The product today is a **static front end**: 52 hand-written HTML pages, vanilla ES5 JS, **no build step, no backend**. All state lives in the browser (`js/store.js` → localStorage + IndexedDB). Eleven `REAL REQUEST` markers stub where network calls belong; the content layer has **zero** markers and is ~40% of the wiring.

The backend must serve five surfaces: a public marketing CMS (32 pages), an admin panel, student auth + portal (passports, transcripts, bank statements, sponsor affidavits, visa forms, police/medical clearances), partner auth, and a multi-tenant partner console (students, applications, a **money wallet/ledger**, a 100k-program search).

### Hard constraints

| Constraint | Consequence |
|---|---|
| **Front end stays vanilla ES5, no build step, forever** | Backend is a *separate* service the static pages call over HTTP |
| **Team: 2–4 developers, modest budget, a consultancy — not a tech company** | Replaceability of the maintainer 18 months out is a first-class requirement |
| **Special-category personal data** (passports, bank/medical/police records) bound for UK/EU/AU institutions | UK/EU GDPR obligations attach on onward transfer; plus partner **commission money** |

### The critical present-day gaps (independent of stack)

1. `admin.html` has **no login at all** and offers full CRUD, a whole-site JSON import, a "reset to demo content" button, and a page kill-switch. A server-backed admin with no auth is **strictly worse** than the current demo — it turns a local demo into a public write endpoint.
2. The contact form **silently discards real leads** today.
3. OTP accepts **any six digits**; all cooldowns are client-side and reset on reload.
4. The partner email-change flow is an **account-takeover vector** as written (`partner-auth.js:1364`).
5. **No password-reset landing page exists anywhere in the repo** — both flows dead-end at "check your inbox".

### The three lenses

Three independent expert reviews scored five candidate stacks. No stack wins all three:

| Lens | Ranking |
|---|---|
| **Security & compliance** | Django (9) > .NET (8.5) > **Laravel (8)** > NestJS (5) > FastAPI (4.5) |
| **Delivery / team-fit** | **Laravel (9)** > Django (8) > NestJS (5) > .NET (4) > FastAPI (3) |
| **Ops / cost** | .NET (8, *conditional*) > NestJS (7.5) > FastAPI (7) = **Laravel (7)** > Django (6.5) |

The final call weights **delivery + local replaceability** (the consultancy's dominant risk) highest, requires **security reachable via framework idioms** (not hand-rolled), and treats ops weaknesses as fixable if they don't require changing the stack.

---

## 2. Decision

Adopt **PHP 8.3 + Laravel + Filament v4 on managed PostgreSQL 16**, deployed same-origin behind one nginx.

| Concern | Choice |
|---|---|
| Language / framework | PHP 8.3+ / Laravel (JSON API + Filament admin + queue runner) |
| Database | **Managed** PostgreSQL 16 (PITR), relational + jsonb |
| ORM / migrations | Eloquent (+ global-scope tenancy trait); raw SQL for search & KPIs; migrations applied as a one-shot deploy job |
| Object storage | Cloudflare R2, two buckets (public CDN images / private scan-gated documents) |
| Cache | Redis (sessions, rate-limit counters, queue backend, content-bundle cache) + Cloudflare CDN |
| Queue / jobs | Redis + Horizon, four named queues (emails, documents, search, default) |
| Search | PostgreSQL flat table + GIN tsvector + `smallint[]` facets + pg_trgm; Typesense only on demand |
| Email | Postmark → SES; SPF/DKIM/DMARC on a dedicated subdomain |
| Auth | Sanctum cookie sessions (HttpOnly, SameSite=Lax), same-origin, 3 cookie scopes, admin TOTP |
| Hosting | One 4GB VPS Singapore/Mumbai + managed Postgres + Cloudflare; Docker Compose via Forge |

### Language specifics

PHP 8.3+ with native enums for the ~8 status vocabularies (application status, document status `missing|uploaded|verified`, journey stage, wallet txn type, review status, seat role, page visibility, doc pack). Typed DTOs and **NUMERIC money** on the wallet paths. PHPStan/Larastan at a moderate level in CI.

### Why Laravel, decisively

- **Replaceable hire in Dhaka.** Deepest, cheapest, most replaceable backend pool in Bangladesh. For a consultancy that will hand this to a contractor, this outranks language elegance and type safety.
- **Filament removes months of the largest work item.** The product is ~2/3 admin/CRUD. The existing `js/admin.js` `SCHEMA` maps almost 1:1 onto Filament resources; its reorderable repeaters + media fields match the country/region/services/partner editors and add login, roles, audit, and reordering — none of which exist today. Closes gap #1 in milestone one.
- **Security reachable via idioms, not hand-rolled.** Sanctum cookie sessions (the correct choice against this codebase's `innerHTML` XSS surface), argon2id, a `password_reset_tokens` migration shipped by default, per-email + per-IP rate limiting in ~10 lines, policies/gates, MFA. Scored 8/10 by the paranoid lens — strong enough, and reached *with* the framework grain.
- **Cheapest to operate** (~$40–50/mo MVP) with the simplest 2am story (request-per-process, no event loop to stall, no async footguns).

### Overrides applied to the winning proposal (grafts)

- **Managed Postgres + PITR**, not the proposal's self-hosted on-box default. This was the ops judge's stated Laravel deal-breaker; a money ledger + passport metadata on `pg_dump`-only backups is a data-loss incident waiting to happen. ~$30/mo buys someone else's backup discipline.
- **RLS under the Eloquent scope** as an independent tenancy net; **migrations as a one-shot deploy job**, never on app start (both grafted from .NET/Django).
- **Defer streaming AI/SSE** (PHP-FPM's genuine weakness) to a small Node/Python side service *only when funded*.

### Supporting choices (implementation detail)

| Area | Choice |
|---|---|
| ORM strategy | Eloquent for aggregate CRUD with a `BelongsToAgency` global-scope trait; raw query builder / SQL for the 40-facet search and the 8 KPI aggregates |
| Tenancy | Eloquent global scope (agency id from **session only, never a request param**) backed by Postgres RLS as an independent second net; CI test fails on any untenanted partner-table query |
| Migrations | Standard Laravel migrations, one-shot deploy job; **preserve blog ids verbatim**; explicit `position INTEGER` on all 10 collections + every override list |
| Object storage | R2 in two buckets — public content-hashed immutable images via CDN; **private** documents with server-generated UUID keys, ClamAV scan-gate before readable, per-request 60–300s presigned GET, append-only `document_access_log` on every mint |
| Auth token | Sanctum SPA/cookie mode — opaque server-side session in an HttpOnly; Secure; SameSite=Lax cookie, same-origin behind one nginx (no CORS, no token in localStorage). Three cookie scopes (student/partner/admin); admin on `/api/admin` with 15-min idle + mandatory TOTP. Double-submit CSRF via one new ~60-line ES5 `js/api.js` + a `Sec-Fetch-Site` check |
| Frontend seam | **Stack-agnostic, highest-leverage decision:** reimplement `js/store.js` as an HTTP client keeping all ~30 exported names identical; inject a per-page content bundle as `<script>window.VFI_BOOTSTRAP={…}</script>` before store.js so the synchronous accessors stay synchronous and 52 pages migrate almost untouched |

---

## 3. Database justification (against this data shape)

The domain is **agencies → students → applications → documents → transactions** — highly relational. PostgreSQL is not a close call:

1. **Money settles it.** `wallet_transactions` must be append-only with a stored `balance_after`, unique idempotency keys on top-ups/fee debits, and a fee-debit atomic with the application insert:

   ```sql
   BEGIN;
   SELECT balance FROM wallets WHERE id = :wallet FOR UPDATE;
   INSERT INTO applications (...) VALUES (...);
   INSERT INTO wallet_transactions (..., balance_after) VALUES (...);
   UPDATE wallets SET balance = :new, version = version + 1 WHERE id = :wallet;
   COMMIT;
   ```

   NUMERIC (never float). A document store makes each of these a hand-rolled reimplementation of what Postgres guarantees.
2. **Tenancy is the #1 stated risk.** FK `agency_id` on every partner-scoped table + **Row-Level Security** (`SET LOCAL app.agency_id`) gives a database-enforced net under the Eloquent global scope. A forgotten `WHERE` returns zero rows, not a competitor's student book. No document DB offers this.
3. **The domain is joins**, and the 8 dashboard KPIs are `GROUP BY status` over a tenant + date range.
4. **Ordering is load-bearing** (`put()` unshifts; the home featured trio and featured blog card are array position) → explicit `position INTEGER` columns.
5. **Blog ids are public URLs** (`blog-post.html?id=b_lx8f2k3`) → preserved verbatim as natural keys.
6. **The genuinely schema-fluid parts** — the 5 override singletons (countries/regions/servicesPage/partnerPage/partnerPortal), the media/page maps, audit before/after, webhook envelopes — go in **jsonb** in the same Postgres. That is the honest answer to relational-vs-document: you need both, and one engine gives you both with nothing extra to back up, patch, or staff.

**Two schema rules carried from the front end:**

- Store event/blog dates as `date`, **not** `timestamptz` — else 2026-09-02 renders as 01 Sep for a Dhaka viewer at UTC+6, the exact bug the current plain-string shape accidentally avoids.
- The API must round-trip `""` / `[]` faithfully (empty = "keep the page's built-in HTML") and never substitute defaults. Corollary: JSON `null` and `""` are **not** interchangeable here; a strict required-field validator on the override objects would break 32 pages. Disable Laravel's `ConvertEmptyStringsToNull` + `TrimStrings` middleware on the content routes.

### Why not a document store (MongoDB / any)

The one area documents help — the 5 CMS override singletons, ~15% of the data — is already covered by Postgres `jsonb`. The majority (money-ledger atomicity, tenant isolation, applications/documents joins) is precisely what you'd then reimplement by hand. A replica set on a $24 VPS to get the transactions Postgres gives for free is a false economy.

### 100k+ program search

**PostgreSQL-first.** 100k programs × 3–6 intakes ≈ **300–600k rows — a small table.** A denormalised flat `program_search` table (rebuild-and-swap on ingest):

- `GIN(to_tsvector(...))` for free text over program + university name.
- `pg_trgm` for typeahead.
- The ~30 requirement/label checkboxes packed into one `smallint[]` with a GIN `@>`/`&&` index.
- Composite b-tree on the high-selectivity scalars (country, level, intake month/year); keyset pagination, never OFFSET.

Filtering 40 facets runs well under 100ms. **The only thing Postgres does badly is live per-facet counts** — and the current UI shows none, so this isn't a requirement. **Exit criterion, decided in advance:** add Typesense (a single Go binary) *only if* the client later demands Algolia-grade instant counts across all facets.

**The real risk is not the engine.** There is **no identified data source** for the catalogue anywhere in the repo — `partner-search.html` is static `<option>` markup and a button that fires a toast. Ingest, refresh cadence, staleness flags, and licensing dwarf the indexing problem, and no stack choice touches them.

---

## 4. Alternatives considered

| Stack | Security | Delivery | Ops/Cost | Verdict |
|---|---|---|---|---|
| **Laravel + Filament + Postgres** | 8 | **9** | 7 | **Chosen** — wins delivery, strong security, cheapest ops; ops weakness fixed by managed Postgres |
| Django + DRF + Postgres | **9** | 8 | 6.5 | Runner-up. Best audited auth + a free staff back-office, but a 2nd language for a JS team, heavier 6-process Celery ops |
| NestJS + Postgres | 5 | 5 | 7.5 | Only same-language option and safest Dhaka ops bet, but ships **zero** admin + **zero** auth batteries — least where the product needs most |
| .NET + EF/Dapper + Postgres | 8.5 | 4 | **8** | Leanest ops on paper, but Dhaka pool skews Windows/IIS/SQL Server → silent costly substitution risk; worst cultural fit |
| FastAPI + SQLAlchemy 2.0 + Postgres | 4.5 | 3 | 7 | Least free of any option (no admin, no auth), hardest async footguns, weakest local handoff |

### Why each was rejected

**Django + DRF (runner-up).** Genuinely strong: the most audited batteries-included auth (argon2id in one line, CSRF middleware a vanilla ES5 client can use, session invalidation on password change via `get_session_auth_hash`), and `django.contrib.admin` nearly-for-free closes the ~9 staff operations that have no UI anywhere in the repo. Rejected on the factor that matters most here: it is a **second language for a JS team** (3–6 weeks' real ramp; a half-learned Django is worse to maintain than plain code), the "free admin" fits the *staff back-office* well but the polished *content CMS* (repeaters/media slots) awkwardly — exactly where Filament shines — and its own killer risk is a 2am ops failure (six processes; non-idempotent Celery tasks re-sending OTPs). Its edge over Laravel on security is real but narrow; Laravel reaches an 8 through its own idioms.

**.NET (ops judge's top pick).** Leanest operational surface on paper (no-Redis MVP, Kestrel's real thread pool, first-class OpenTelemetry) and the best secure-defaults-under-one-lifecycle. Rejected because its top score is **explicitly conditional** on the team being able to operate Linux + Postgres + Docker — and the Dhaka .NET pool skews Windows/IIS/SQL Server. The likely failure mode is a **silent substitution** to a licensed Windows + SQL-Server stack a non-technical client cannot detect until the invoice, erasing every cost advantage. Worst cultural fit for a "no toolchain, open the file" team. (Corollary correction: if ever chosen, start on **.NET 10 LTS**, not .NET 8 — .NET 8 support ends Nov 2026.)

**NestJS (only same-language option).** The one stack that avoids a second language — but it can't share types or validation with the frozen ES5 front end, so it's one language, not one codebase. Ships **zero** admin and **zero** auth batteries, so sessions/OTP/lockout/reset/TOTP **and** ~60 CRUD endpoints are hand-written — delivering least exactly where this admin/security-heavy product needs most. Its `@nestjs/passport` docs steer toward **JWT-in-localStorage**, which is actively dangerous against this codebase's `innerHTML` XSS surface.

**FastAPI + SQLAlchemy 2.0.** Worst delivery/team-fit profile: gives the **least for free** (no admin AND no auth batteries), the hardest learning cliff (async SQLAlchemy `MissingGreenlet`/N+1; argon2 inline in an async handler silently serialises logins; a mis-pooled `SET LOCAL` silently disables the RLS backstop), and the weakest Dhaka handoff story. Its genuine strengths (OpenAPI contract, clean SSE/LLM streaming) aim at deferred features, not the admin/auth/CRUD mountain that is the near-term work.

**MongoDB / any document store.** See §3 — the ~15% it helps is already covered by Postgres `jsonb`, and the majority is what you'd reimplement by hand.

---

## 5. Ideas grafted from the losing proposals

| Graft | Source | Why |
|---|---|---|
| **Managed Postgres + PITR** instead of self-hosted on-box default | .NET, Django ops critiques | Fixes the ops judge's stated Laravel deal-breaker: a money ledger + passport metadata on `pg_dump`-only backups is a data-loss incident waiting to happen |
| **Migrations as a one-shot deploy job**, never auto-migrate on app start | .NET | Avoids the multi-replica race that lets a bad migration take the site down at deploy |
| **Postgres RLS as an independent second tenancy net** under the Eloquent scope | every relational proposal | A forgotten `WHERE` returns zero rows instead of a competitor's student book |
| **Opaque `flow_id`** replacing `?email=` in both verify flows | all proposals | Kills the PII-in-URL leak AND the unauthenticated account-takeover vector at `partner-auth.js:1364` |
| **Defer streaming LLM / SSE** to a small Node/Python side service when funded | FastAPI, Nest strengths | Sidesteps PHP-FPM's request-per-worker weakness without compromising the main stack |
| **CI test that fails on any untenanted partner query** | every relational proposal | Makes tenancy default-deny at the pipeline level, not per-controller discipline |
| **Presigned direct-to-R2 upload as a v2 optimisation**, scan-gated | all proposals | Start with browser→API→magic-byte sniff→ClamAV→storage so nothing is readable before scan |
| **Disable `ConvertEmptyStringsToNull` + `TrimStrings`** on content routes | Laravel-specific landmine | Preserves the load-bearing "empty means keep the page's built-in HTML" contract across 32 pages |
| **Ship admin-auth + contact-form persistence in weeks 1–3** | every judge | A five-month backend is the wrong answer to a this-week emergency |

---

## 6. Consequences

### Good

- The largest work item (admin CRUD, roles, audit, reorder, login) is largely delivered by Filament, mapping onto the existing SCHEMA.
- Same-origin topology eliminates CORS and makes HttpOnly cookie sessions — immune to the existing `innerHTML` XSS surface — the natural choice, with real logout/revocation.
- One managed Postgres does relational + jsonb + full-text search + job-queue backing (`FOR UPDATE SKIP LOCKED` if ever needed); no second datastore to secure.
- Cheapest infra (~$40–50/mo MVP) and the simplest failure surface for a no-ops team; Forge removes hand-configuring nginx/certbot/supervisor.
- Most replaceable maintainer in the local market.
- The `js/store.js` HTTP-client seam migrates 52 pages almost untouched and keeps the backend swappable behind an API contract.

### Bad / accepted

- **Second language alongside ES5** (unavoidable except NestJS); client-side FILTERS/validation must be re-implemented server-side and kept in sync by discipline.
- **PHP-FPM is request-per-worker** → poor at streaming LLM/SSE and long-held uploads. Presigned direct-to-R2 for uploads and a deferred side service for AI mitigate this. Same-origin means an exhausted FPM pool could take the marketing site down — set a **separate FPM pool for uploads**.
- **Filament is Livewire** → big repeaters feel sluggish over Dhaka latency; Filament major-version upgrades carry a maintenance tax.
- **`ConvertEmptyStringsToNull`/`TrimStrings`** must be disabled on content routes or the empty-means-fall-through contract breaks silently across 32 pages.
- Sanctum locks in same-origin; moving the static site to a different registrable domain later weakens the cookie posture (forces SameSite=None).
- Facet counts on search would force adding Typesense (a second thing to run).

### Non-negotiable security posture (built in order)

1. **Admin auth + TOTP before any admin content endpoint is exposed.**
2. Tenant isolation: Eloquent scope + RLS + a CI test that fails on any untenanted partner query.
3. Private document bucket, server-generated keys, ClamAV scan-gate, per-request presigned GET, append-only `document_access_log`.
4. Server-side rate limits on sign-in/register/forgot/OTP; hashed, TTL'd, attempt-capped OTP bound to an opaque `flow_id` (kills the email-change takeover vector).
5. Build the two missing password-reset landing pages.
6. URL-scheme allow-list on `ppQuicklinks.url` / `ppDocs.url` / `services_blocks.ctaHref`; keep the blog body plain-text end-to-end.
7. GDPR from the schema up: subject export, erasure with legal-hold exception, per-document retention clock, record of onward disclosure.

### First increment (weeks 1–3, regardless of everything else)

Admin authentication + contact-form persistence. A five-month backend is the wrong answer to a live this-week emergency.

---

## 7. Reversibility

**Moderate, and deliberately structured to stay that way.**

- **Data:** standard PostgreSQL — fully portable to any stack.
- **Frontend:** decoupled by the `js/store.js` HTTP-client seam — the 52 pages depend on an HTTP *contract*, not on Laravel. Swapping the backend later means re-implementing endpoints behind the same contract with zero frontend churn.
- **Lock-in cost is narrow:** Filament (the admin UI) would need rebuilding if you left Laravel (~3–4 weeks); Sanctum's cookie model assumes the same-origin topology (keep it same-origin).

You are not married to PHP — you are married to the API contract and to keeping the site same-origin. Both are cheap to honour.

---

## 8. Review trigger

Revisit this decision if **any** of these occur:

- The partner console's **streaming AI assistant / mock interview becomes funded, in-scope, and high-volume** → stand up the side service (decided here) or reconsider if it becomes the product's centre of gravity.
- The client demands **live per-facet search counts** → add Typesense (planned exit) or re-evaluate search infrastructure.
- **Two interview rounds cannot surface a Laravel + Postgres + Linux hire** in Dhaka → the core hiring premise has failed; reconsider toward the next-most-hireable local stack.
- **Program search sustains p95 > 400ms** at real catalogue size, or the catalogue grows an order of magnitude beyond ~600k searchable rows.
- The static site must move to a **different registrable domain** from the API → re-evaluate the cookie/CORS topology before doing so.
- Partner agencies scale past **a few hundred** with real concurrent load → add a second app node + managed Redis (a scale step, not a re-architecture).

---

See also: [Executive summary](00-executive-summary.md).
