# memory.md — backend agent orientation

Read this **before** exploring. It replaces a repo-wide scan. [README.md](README.md) is the human index; this is for agents doing backend work.

**Relationship to the other memory.md:** the repo-root [`../memory.md`](../memory.md) describes the **static front end** (52 HTML pages, vanilla ES5 JS, no build step). **This** file describes the **backend** — a separate Laravel service the front end calls over HTTP. The front end stays vanilla with **no build step, ever**; the backend never adds npm/bundlers/framework code to the front end. Only the internals of `js/store.js` (and a new `js/api.js`) change, keeping every exported name identical.

> The backend does not exist yet. Design + plan only. The repo root has no PHP. Everything below is what you will *create*. Check the current phase doc before assuming anything is built.

---

## Hard constraints — do not violate

| Rule | Why |
|---|---|
| **Front end stays vanilla HTML/CSS/ES5 JS, no build step** | Open `index.html` and it works. Backend is a *separate* service. Never add `package.json`/bundler to the site root. |
| **ES5 style in `js/`** | `var`, `function`, string concat. Any new front-end code (`js/api.js`, `store.js` rewrite) matches this — no `const`/`let`/arrow/template literals. |
| **Same-origin topology** | One nginx serves the 52 static files AND proxies `/api/*` to PHP-FPM. Sanctum cookie auth depends on it. No CORS. Do not split the API onto another registrable domain. |
| **`store.js` keeps all ~30 exported names** | The migration seam. 52 pages depend on the HTTP *contract*, not on Laravel. Rename nothing. |
| **Synchronous accessors stay synchronous** | `list/get/settings/media/country/region/servicesPage/partnerPage/pageEnabled` read the injected `window.VFI_BOOTSTRAP` bundle. Do not make them Promise-based. |
| **Empty means fall through** | `""` / `[]` = "keep the page's built-in HTML". API round-trips empty faithfully, never substitutes defaults. Disable `ConvertEmptyStringsToNull` + `TrimStrings` on content routes. |
| **Order is the array** | `put()` unshifts new items to the FRONT. Every collection + override list has an explicit `position INTEGER`. |
| **Blog ids are public URLs** | `blog-post.html?id=<legacy_id>`. Preserve verbatim as a natural key or every shared link dies. |
| **Tenancy from the session only** | Partner `agency_id` comes from the session, never a request param. Enforced by Eloquent global scope + Postgres RLS + a CI guard test. |
| **Migrations never auto-run on app boot** | One-shot deploy job with an advisory lock. Expand/contract: release N migration must be safe against N-1 code. |

---

## Chosen stack

| Layer | Choice | Notes |
|---|---|---|
| Framework | Laravel (PHP 8.3+) | JSON API + Filament v4 admin + Horizon queues |
| DB | Managed PostgreSQL 16 + PITR | Relational spine; `jsonb` for 5 CMS override singletons |
| ORM | Eloquent + raw SQL | Eloquent for CRUD (+ `BelongsToAgency` global scope); raw SQL for the 40-facet search + 8 KPI aggregates |
| Admin | Filament v4 | Maps ~1:1 onto existing `js/admin.js` SCHEMA; adds login/roles/audit/reorder |
| Auth | Sanctum cookie sessions | HttpOnly/Secure/SameSite; 3 scopes; admin on `/api/admin` + TOTP; double-submit CSRF via `js/api.js` |
| Cache/queue | Redis + Horizon | Sessions, rate-limit/OTP counters, 4 named queues (emails/documents/search/default), content-bundle cache |
| Storage | Cloudflare R2 | Public content-hashed images (CDN) + PRIVATE scan-gated documents (ClamAV, presigned 60–300s GET) |
| Search | Postgres flat table | `program_search` (~300–600k rows): GIN tsvector, `pg_trgm`, `smallint[]` facet bitset. Typesense only if live facet counts demanded |
| Email | Postmark → SES | SPF/DKIM/DMARC; bounce/complaint webhooks → suppression |
| Hosting | 1× 4GB VPS + Cloudflare | nginx + PHP-FPM (separate upload pool) + Redis + Horizon + ClamAV; Forge-provisioned Docker Compose |

Rationale + rejected stacks: [01 Tech stack decision](01-tech-stack-decision.md). Do not re-litigate — it is an Accepted ADR.

---

## Repo / service layout (to create)

Backend lives in a **separate directory / repo** from the static site. The static site is imported unchanged.

```
(static site — unchanged)   *.html, css/, js/, assets/   ← vanilla, no build step
  js/store.js       becomes an HTTP client (same ~30 names) reading window.VFI_BOOTSTRAP
  js/api.js         NEW ~60-line ES5 helper: base URL, credentials:'include', CSRF double-submit, 401 redirect
  admin-login.html          NEW — gates admin.html
  student-reset.html        NEW — password-reset landing (missing from repo today)
  vfi-partner-reset.html    NEW — partner password-reset landing

(backend — Laravel)
  app/Models/            Eloquent models (+ BelongsToAgency trait)
  app/Http/Controllers/  JSON API controllers (thin)
  app/Filament/          admin panel resources (mirror js/admin.js SCHEMA)
  app/Policies/          role/tenant gates
  app/Enums/             PHP 8.3 enums mirroring the ~8 PG status vocabularies
  app/Jobs/              Horizon jobs (email, AV scan, search ingest, rollups)
  database/migrations/   applied as a one-shot deploy job (never on boot)
  routes/api.php         /api/* (public content, auth, student, partner) + /api/admin/*
  nginx/                 same-origin config: static files + /api proxy
  docker-compose.yml     nginx, php-fpm, redis, horizon, clamav
```

---

## Module map (surfaces → backend area)

| Surface | Front-end today | Backend area |
|---|---|---|
| Public CMS (32 pages) | `js/render.js` + `js/store.js` | content read bundle + Filament CMS |
| Admin panel | `js/admin.js` (no login) | Filament v4 behind admin auth + TOTP |
| Student auth | `js/auth.js` (4 REAL REQUEST markers) | `users`, OTP (flow_id), password reset |
| Student portal | `js/student-portal.js` (0 markers; upload discards File) | profile + document packs (R2, scan-gated) + tracking |
| Partner auth | `js/partner-auth.js` (6 markers) | agencies, review workflow, tenancy |
| Partner console (11 pages) | `js/portal.js` (1 marker @ 430) + `js/portal-render.js` | tenant-scoped students/applications/enquiries/search/wallet |
| Contact form | `js/main.js` ~421 (unmarked, silently discards) | `contact_enquiries` + staff inbox |

Full entity list, enums, and the relational/`jsonb` split: [03 Data model](03-data-model.md). Endpoint shapes: [04 API contract](04-api-contract.md).

---

## Phase docs

Roadmap, gate model, parallelisation: [phases/README.md](phases/README.md). Per-phase scope/exit-gate/tests: `phases/phase-0.md` … `phases/phase-9.md`.

| Phase | Ships | Wires |
|---|---|---|
| P0 | Platform: repo, CI/CD, containers, managed PG+PITR, Redis, R2, `store.js` seam skeleton | none (still local `VFI_BOOTSTRAP`) |
| P1 | **Identity spine + admin auth+TOTP (P0 emergency)**, tenancy machinery + CI guard | `admin-login.html` |
| P2 | Public content READ bundle + **contact-form persistence (P0 emergency)** | contact form; `store.js` content accessors |
| P3 | Admin CMS WRITE (Filament): CRUD/reorder/media/pages/backup/audit | admin-side |
| P4 | Student auth: register/login/OTP/reset + `student-reset.html` | `js/auth.js` all 4 markers |
| P5 | Student portal: profile + document packs (scan-gated) + tracking | portal guard + real upload |
| P6 | **Partner identity + tenancy (hard gate)**: registration/review/sign-in + `vfi-partner-reset.html` | `js/partner-auth.js` all 6 markers |
| P7 | Partner console core: students/applications/enquiries/QR referral | `js/portal.js:430` (split per form) |
| P8 | Program search: 100k catalogue, ingest, 40-facet query | search endpoint |
| P9 | Money surface + staff back-office + GDPR + production launch | wallet/ledger; AI/allied flag-OFF |

Hard gates that cannot be waived: **P1 admin auth**, **P6 tenant isolation**.

---

## Key commands (once P0 lands)

```bash
# Local up (Docker Compose: nginx, php-fpm, redis, horizon, clamav)
docker compose up -d
docker compose exec app php artisan migrate      # NEVER auto-run on boot
docker compose exec app php artisan db:seed       # imports from a VFI.exportAll JSON

# Content import from the existing front-end backup (idempotent, upsert on legacy_id)
docker compose exec app php artisan vfi:import path/to/vfi-backup.json

# Tests, static analysis, style
docker compose exec app php artisan test          # Pest/PHPUnit
docker compose exec app ./vendor/bin/phpstan analyse   # Larastan, moderate level
docker compose exec app ./vendor/bin/pint            # code style

# Security / CI-local
gitleaks detect                                     # secret scan (BLOCK in CI)
# CVE + license + SAST run in GitHub Actions; the tenancy guard test is a normal artisan test
```

Deploy = build → cosign sign → push → `docker compose pull && up -d` + a **separate one-shot migrate job** with an advisory lock. An unsigned image is rejected at deploy. See [06 DevSecOps pipeline](06-devsecops-pipeline.md) and [08 Environments & runbooks](08-environments-and-runbooks.md).

---

## Store → database migration status

| Area | Today (front end) | Target (backend) | Phase |
|---|---|---|---|
| Content read | `VFI.list/get/settings/...` from localStorage blob | `GET /content/bundle?page=` → `window.VFI_BOOTSTRAP`, sync accessors unchanged | P2 |
| Content write | `js/admin.js`, no auth | Filament CMS behind admin auth + audit | P3 |
| Images | IndexedDB base64 data URLs | R2 content-hashed CDN URLs; `getImage` dual-mode preserved (path/URL ids pass through) | P2/P3 |
| Contact form | `js/main.js`, discarded | `POST /contact` + Turnstile + staff inbox | P2 |
| Student auth | 4 `REAL REQUEST` stubs (`js/auth.js`) | real endpoints, OTP by `flow_id` | P4 |
| Student portal | localStorage, filenames only | self-scoped API + real scanned uploads | P5 |
| Partner auth | 6 `REAL REQUEST` stubs (`js/partner-auth.js`) | agencies + tenancy | P6 |
| Partner console | 1 `REAL REQUEST` stub (`js/portal.js:430`) + static empty states | tenant-scoped CRUD, search, wallet | P7–P9 |

**11 `REAL REQUEST` markers total** — 4 in `js/auth.js` (738, 859, 965, 1115), 6 in `js/partner-auth.js` (761, 980, 1016, 1243, 1318, 1364), 1 in `js/portal.js` (430). Plus **2 unmarked write paths**: contact form (`js/main.js` ~421) and student document upload (`js/student-portal.js` ~600–611). Wire one flow at a time behind a Laravel Pennant flag; remove the per-page demo disclaimer as each goes live.

---

## Task → file routing

| To do… | Work in |
|---|---|
| A new content collection | migration (table + `position`) → Eloquent model → Filament resource → include in `GET /content/bundle` → `store.js` accessor already exists |
| A CMS override singleton edit | `jsonb` column + `version`; Filament form with optimistic-concurrency guard; round-trip `""`/`[]` faithfully |
| Image upload | controller: magic-byte sniff → re-encode/downscale → R2 public bucket → content-hash URL; reference-counted deletion (never orphan a shared/path-style id) |
| A student/partner auth flow | route + controller + rate-limit + the matching `REAL REQUEST` marker on the front end; OTP bound to `flow_id`, hashed, TTL, attempt cap |
| A document upload | multipart → magic-byte + size cap → ClamAV scan-gate (unreadable until clean) → PRIVATE R2, server UUID key → `document_access_log`; presigned single-use GET |
| A partner-scoped read/write | model uses `BelongsToAgency`; `agency_id` from session; RLS policy present; add a tenancy test |
| The 8 dashboard KPIs | raw SQL `GROUP BY status` over the tenant + date range; never client-filter a global list |
| Program search | write to the flat `program_search` table via the ingest job; query with the `smallint[]` facet bitset + tsvector |
| A money operation | `NUMERIC`, append-only ledger with `balance_after`, idempotency key, serialisable txn + `wallets.version` lock; credit only from verified deduped webhooks |
| Admin panel view | Filament resource under `app/Filament/`; role-gate via a Policy; every mutation writes `content_audit_log` |
| Page on/off | fixed allow-list catalogue server-side (`setPage` cannot write arbitrary filenames); privileged + audited |
| The front-end call site | `js/store.js` (keep the name) or `js/api.js`; ES5 only; do not add a build step |

---

## Landmines — backend-specific

1. **Empty-string contract is load-bearing across 32 pages.** A `null`/default substitution silently blanks or duplicates page sections. Keep `ConvertEmptyStringsToNull` + `TrimStrings` OFF on content routes; `""` and `null` are NOT interchangeable here.
2. **Bootstrap must be injected before `store.js`.** The sync accessors read `window.VFI_BOOTSTRAP`. A page-load-order regression breaks every public page silently. Do not convert accessors to async as a "fix".
3. **Dates are `DATE`, not `timestamptz`.** An event dated `2026-09-02` stored as a timestamp renders as 01 Sep for a Dhaka viewer (UTC+6). Store plain dates; format client-side.
4. **`getImage` dual-mode must survive.** Ids that look like a path/URL (contain `/`, start `http(s):`, or end in an image extension) resolve to themselves — that is how the bundled `assets/img/*.jpg` defaults work. `delImage` on a path-style id must no-op, never touch a static file.
5. **Image deletion needs reference counting.** SEED reuses paths like `assets/img/city-uk.jpg` across rows. Blind delete-on-remove destroys shared assets.
6. **`store.js:430`… no — `portal.js:430` is ONE shared handler for BOTH global modals.** Split it per form before adding network calls or you break student registration and enquiry submission at once.
7. **The partner email-change flow is an account-takeover vector as written.** Bind OTP to a server-side pending record keyed by `flow_id`; never re-point a code to a client-supplied address.
8. **OTP: any six digits pass today, cooldowns are client-only.** Server-side: CSPRNG, hashed, TTL, attempt cap, single-use, prior-code invalidation, per-email + per-IP rate limits.
9. **Sample payloads in the front-end comments are wrong.** `js/auth.js:743` drops name + terms; `js/partner-auth.js` calls a `collect()` that does not exist and the wizard spans three DOM subtrees. Derive real payloads from the markup, not the comments.
10. **Tenancy is default-deny in ONE layer, not per controller.** Eloquent global scope + RLS second net. A forgotten `WHERE` must return zero rows, not a competitor's student book. The CI guard test fails on any untenanted partner query — keep it green.
11. **Migrations as a one-shot job, advisory-locked.** Auto-migrate on boot races across replicas and can take the site down at deploy.
12. **Money is append-only from the first real transaction.** Ledger `UPDATE`/`DELETE` denied to the app role; balance == signed sum; idempotency keys everywhere; credit only on verified deduped signed webhooks — never a browser redirect.
13. **URL-scheme allow-list on admin-authored URLs.** `ppQuicklinks.url`, `ppDocs.url`, `services_blocks.ctaHref` go straight into `href`; a `javascript:` value executes. Allow http/https/mailto/relative only, server-side.
14. **Blog body is plain text by contract.** Keep it plain-text end to end (server validates); a rich-text editor accepting HTML becomes stored XSS on a public page.
15. **Page on/off is cosmetic today.** It strips nav links + swaps `#main`; the HTML still serves. If it must actually hide, enforce 404/410 at the edge and say so in the admin copy.
16. **Same-origin is a Sanctum dependency.** Prove the nginx static + `/api` proxy in P0; moving the site to another registrable domain later forces SameSite=None and a weaker posture.

---

## Testing

Backend tests are Pest/PHPUnit against a real Postgres + Redis (Docker Compose), not mocks where a real dependency is the thing under test (RLS, ClamAV scan-gate, rate limits, webhooks).

**A phase is done only when its exit-gate criteria are green in CI AND the attack it prevents actually fails in a negative test** — not "looks done". Standing gates on every phase: secrets scan, fixable Critical/High CVEs, SAST-high, the untenanted-query tenancy test, denied licenses (all BLOCK); expand/contract migration safety.

Non-negotiable negative tests by area:

| Area | The test that must fail the attack |
|---|---|
| Admin auth (P1) | No admin route/`admin.html` content reachable without a session; TOTP cannot be bypassed |
| Tenancy (P6/P7) | A forged `agency_id` in body/query changes nothing; stripping the Eloquent scope returns zero rows via RLS |
| OTP (P4/P6) | Wrong code rejected, 6th attempt destroys it, expiry enforced, resend invalidates prior; brute-force/amplification fails |
| Enumeration (P4) | register + forgot return identical response AND timing for existing vs unknown email |
| Uploads (P5/P7) | EICAR file quarantined and never served; wrong-magic-byte rejected; presigned GET single-use + expiring; every read logged |
| Money (P9) | Replayed webhook credits once; fee debit atomic with application create; ledger `UPDATE`/`DELETE` denied |
| Content (P2) | Blank override leaves built-in HTML standing; `javascript:` URL refused; blog HTML stored/rendered as plain text |

Front-end verification unchanged from the root memory.md (headless Chrome over CDP) — the pages must render identically from the API bundle as they did from `VFI_BOOTSTRAP`. See [07 Testing strategy](07-testing-strategy.md).

---

## Conventions

- Brand is **VFI**. Placeholder contacts `dhaka@vfi-edu.com`, Gulshan, Dhaka; phones `+880 …`. Seed dates are 2026.
- British-leaning spelling (organise, colour).
- PHP 8.3 native enums mirror the PG status vocabularies; `NUMERIC` (never float) on money paths; typed DTOs on wallet paths.
- Remove a page's front-end demo disclaimer only as that flow goes live behind its Pennant flag.
- Never weaken the front end toward a build step to make backend wiring easier. If a call is awkward, fix it in `js/store.js`/`js/api.js` in ES5.
- The root [`../memory.md`](../memory.md) is authoritative for anything front-end; this file for anything backend. Keep the stack table here in sync with [README.md](README.md).
