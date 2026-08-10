# VFI backend documentation

The index for the VFI Overseas Education **backend** documentation set. For the engineers and AI agents who will build, review, and operate the backend service that the existing static front end calls over HTTP.

This set covers the backend only. The front end (52 static HTML pages, vanilla ES5 JS, no build step) is documented in the repo-root [`memory.md`](../memory.md) and is **not** changing — it stays vanilla and calls the backend behind the `js/store.js` HTTP-client seam. See [backend memory.md](memory.md) for that relationship.

> Status: the backend does not exist yet. These documents are the design and the plan. The first code lands in Phase 0. Do not assume any table, endpoint, or service in here is built — check the phase docs for what is actually shipped.

---

## Chosen stack (one-glance)

| Layer | Choice |
|---|---|
| Language / framework | PHP 8.3+ / Laravel (JSON API + Filament v4 admin + queue runner) |
| Database | **Managed** PostgreSQL 16 with PITR — relational spine + `jsonb` for the 5 schema-fluid CMS override singletons |
| Admin UI | Filament v4 (maps ~1:1 onto the existing `js/admin.js` SCHEMA) |
| Auth | Laravel Sanctum cookie sessions (HttpOnly, Secure, SameSite=Lax), **same-origin**, 3 cookie scopes (student/partner/admin), admin TOTP |
| Cache / queue | Redis (sessions, rate-limit + OTP counters, Horizon queues, content-bundle cache) |
| Object storage | Cloudflare R2 — public content-hashed images (CDN) + private scan-gated documents |
| Search | PostgreSQL-first flat `program_search` table (GIN tsvector + `pg_trgm` + `smallint[]` facet bitset); Typesense only on demand |
| Email | Postmark → Amazon SES; SPF/DKIM/DMARC on a dedicated subdomain |
| Hosting | One 4GB VPS (Singapore/Mumbai) behind one nginx: PHP-FPM + Redis + Horizon + ClamAV; managed Postgres; Cloudflare + Turnstile |
| Deploy | Docker Compose via Laravel Forge; GitHub Actions build → signed image → one-shot migrate job |

Full rationale and the rejected alternatives are in [01 Tech stack decision](01-tech-stack-decision.md).

---

## The two things that are load-bearing

1. **Migration seam.** `js/store.js` is reimplemented as an HTTP client keeping all ~30 exported names identical, fed by a per-page `window.VFI_BOOTSTRAP` content bundle injected before it runs. The 52 pages depend on an HTTP *contract*, not on Laravel. See [02 Architecture](02-architecture.md).
2. **Empty means fall through.** `""` / `[]` in content = "keep the page's built-in HTML". The API must round-trip empty faithfully and never substitute defaults, or 32 public pages lose sections. Laravel's `ConvertEmptyStringsToNull` + `TrimStrings` middleware is disabled on content routes. See [03 Data model](03-data-model.md) and [05 Security & compliance](05-security-and-compliance.md).

---

## Documents

| Document | Read this when… |
|---|---|
| [README.md](README.md) | You need the map — what exists, who it's for, where to start. (This file.) |
| [memory.md](memory.md) | You are an agent about to do backend work and want the minimum orientation: constraints, layout, commands, routing, landmines. **Start here for any code task.** |
| [00-executive-summary.md](00-executive-summary.md) | You have five minutes and need the what/why/cost/timeline for a non-technical stakeholder. |
| [01-tech-stack-decision.md](01-tech-stack-decision.md) | You want to know why Laravel/Postgres was chosen over Django/.NET/Nest/FastAPI — or you're tempted to re-litigate it. (Don't; read this first.) |
| [02-architecture.md](02-architecture.md) | You need the service topology, same-origin proxy model, the store.js seam, request lifecycle, tenancy enforcement, or where a component lives. |
| [03-data-model.md](03-data-model.md) | You are writing a migration or a query and need tables, enums, the relational vs `jsonb` split, ordering/position columns, or the empty-string contract. |
| [04-api-contract.md](04-api-contract.md) | You are wiring a front-end call or building an endpoint and need the request/response shape, the content-bundle format, or which `REAL REQUEST` marker maps to which route. |
| [05-security-and-compliance.md](05-security-and-compliance.md) | You touch auth, tenancy, uploads, money, PII, OTP/reset, CSRF, or GDPR. Also the negative tests each control must pass. |
| [06-devsecops-pipeline.md](06-devsecops-pipeline.md) | You are changing CI/CD, image signing, the migration deploy job, secret scanning, or the BLOCK/WARN gate policy. |
| [07-testing-strategy.md](07-testing-strategy.md) | You are writing tests or need to know what proves a phase done — including the tenancy CI guard and the per-phase negative tests. |
| [08-environments-and-runbooks.md](08-environments-and-runbooks.md) | You are deploying, rolling back, restoring a backup, rotating a secret, or responding to a pager alert. |
| [phases/README.md](phases/README.md) | You need the roadmap overview, the gate model, parallelisation rules, and the phase index. |
| [phases/phase-0 … phase-9](phases/) | You are executing a specific phase and need its scope, exit gate, security work, tests, and front-end wiring. |

---

## Start here

**New backend developer**
1. [memory.md](memory.md) — orientation and hard constraints.
2. [01 Tech stack decision](01-tech-stack-decision.md) — why the stack is what it is.
3. [02 Architecture](02-architecture.md) + [03 Data model](03-data-model.md) — how it fits together.
4. [phases/README.md](phases/README.md) then the current phase doc — what to build now.
5. [08 Environments & runbooks](08-environments-and-runbooks.md) — get a local stack up.

**AI coding agent**
1. [memory.md](memory.md) — replaces a repo-wide scan; has the module map, task→file routing, commands, and landmines.
2. The **current phase** doc under [phases/](phases/) — scope and exit gate for the task in hand.
3. [04 API contract](04-api-contract.md) or [03 Data model](03-data-model.md) — whichever the task touches.
4. [05 Security & compliance](05-security-and-compliance.md) — before writing anything that touches auth, tenancy, uploads, or money.
5. Read the repo-root [`memory.md`](../memory.md) only when you must change how the front end calls the API — and keep the front end vanilla, no build step.

**Client / product owner**
1. [00 Executive summary](00-executive-summary.md) — the whole thing in five minutes.
2. [phases/README.md](phases/README.md) — what ships when, and what each phase demonstrates.
3. [01 Tech stack decision](01-tech-stack-decision.md) §Reversibility and §Review trigger — what you are and aren't locked into.

---

## Conventions for this doc set

- Terse, table-driven, written for engineers and agents. No marketing filler.
- British-leaning spelling (organise, colour) to match the repo.
- Every document opens with a one-line statement of what it is and who it is for.
- Cross-link siblings with relative links. Keep the stack table above and in [memory.md](memory.md) in sync if the decision ever changes (it is Accepted; changing it needs a new ADR).
- The front end is out of scope here except at the HTTP contract. It must remain vanilla HTML/CSS/JS with **no build step** — see the root [`memory.md`](../memory.md).
