# Backend Blueprint — Workflow Result (orchestrator synthesis)

My one-page summary of the multi-agent backend-design run (27 agents: discover → 5 stack proposals → 3 independent judge panels → decide → design → 10-phase roadmap → author). The full deliverables are the sibling docs listed at the bottom; **the authoritative stack record is [01-tech-stack-decision.md](01-tech-stack-decision.md)**.

Run: `wf_1bcaf7cd-4cc` · 27/27 agents, 0 errors.

## Decision

| | Choice |
|---|---|
| **Language** | PHP 8.3+ (native enums for the ~8 status vocabularies; typed DTOs + `NUMERIC` money; PHPStan/Larastan in CI) |
| **Framework** | **Laravel** (JSON API + Filament v4 admin + queue runner) |
| **Database** | **Managed PostgreSQL 16** with PITR — *not* self-hosted on-box |
| **Admin UI** | Filament v4 (maps ~1:1 onto the existing `js/admin.js` SCHEMA) |

## Judge scorecard (each lens scored all 5 stacks independently)

| Lens | Top pick | Note |
|---|---|---|
| Security & compliance | **Django** | most-audited batteries; but a 2nd language for a JS team |
| Delivery & team-fit | **Laravel** | deepest + most replaceable Dhaka hiring pool; free admin/auth |
| Ops, scale & cost | **.NET** | but Dhaka .NET pool skews Windows/IIS/SQL-Server (illusory fit) |

Lead's call: **Laravel** is the only option scoring 8+ on delivery, 8 on security, and cheapest on ops simultaneously — the decisive factors for a small Dhaka consultancy (local replaceability + how much security/admin ships as audited defaults over passport scans and a money ledger).

## Supporting choices

| Concern | Choice |
|---|---|
| Tenancy | Eloquent `BelongsToAgency` global scope (agency id from **session only**, never a request param) **backed by Postgres Row-Level Security** as a second net; CI fails on any untenanted partner query |
| Auth | **Laravel Sanctum cookie mode** — opaque server-side session in `HttpOnly; Secure; SameSite=Lax`, **same-origin ⇒ no CORS, no token in localStorage** (matters given the existing innerHTML XSS surface); 3 cookie scopes; admin on `/api/admin` + 15-min idle + mandatory TOTP; double-submit CSRF via a new ~60-line ES5 `js/api.js` |
| Object storage | Cloudflare R2 — public content-hashed images via CDN; **private** documents (UUID keys, ClamAV scan-gate, 60–300s presigned GET, append-only `document_access_log`) |
| Search | **Postgres-first**: denormalized `program_search` table (~300–600k rows), GIN `tsvector` + `pg_trgm`, requirement flags in a `smallint[]` GIN index; Typesense only if live facet counts are later demanded |
| Jobs / cache | Redis + Horizon (systemd), 4 named queues so an AV scan never delays an OTP; Redis sessions/rate-limits/ETag cache |
| Email | Postmark at MVP (OTP deliverability is the front door) → Amazon SES once the subdomain has reputation; SPF+DKIM+DMARC |
| Hosting | One 4GB VPS (Vultr/DO **Singapore** or AWS **Mumbai** — region matters for Dhaka latency) + **managed** Postgres; Cloudflare free + Turnstile on unauth writes; provisioned by Laravel Forge |
| **Frontend seam (highest-leverage)** | Reimplement `js/store.js` as an HTTP client keeping all ~30 exported names identical; inject a per-page `window.VFI_BOOTSTRAP={…}` before it so the synchronous accessors stay synchronous — **52 pages migrate almost untouched, no build step** |

## Runners-up (and why not)

- **Django + DRF** — security judge's pick; most-audited auth + free staff admin, but adds a 2nd language to a JS team and a heavier 6-process Celery ops surface.
- **NestJS + Postgres** — safest ops given Dhaka's deep JS pool and the only same-language option, but ships **zero** admin and **zero** auth batteries — least help exactly where this admin/security-heavy product needs most.

## Reversibility

Moderate, and deliberately kept that way: business data is standard PostgreSQL (portable to any stack), and the frontend depends on an **HTTP contract**, not on Laravel (swap the backend later behind the same contract, zero frontend churn). Real lock-in is narrower than a rewrite: **Filament** (~3–4 weeks to rebuild if you leave Laravel) and Sanctum's **same-origin** assumption (keep the static site same-origin, or you drop to `SameSite=None` and a weaker posture).

## 10-phase roadmap (see `phases/`)

Security is embedded in every phase; the currently-**unauthenticated admin panel is locked down in Phase 1** (P0). Money is deferred to the last phase — aligned with your "wallet/payments free for now" decision.

| Phase | Focus |
|---|---|
| 0 | Platform & delivery foundation |
| 1 | Identity spine & **admin lockdown** |
| 2 | Public content read path (`store.js` seam) + contact-form persistence |
| 3 | Admin CMS write path (Filament: content/media/pages/backup/audit) |
| 4 | Student identity (register / sign-in / OTP / reset) |
| 5 | Student portal (profile / document packs / application tracking) |
| 6 | **Partner identity & tenancy** |
| 7 | **Partner console core** |
| 8 | Program search |
| 9 | Money surface, staff back-office & launch |

> Phases 6–7 (partner) + 5 (student tracking) are exactly the **core partner→student loop** from §14 of `BACKEND_DEVELOPMENT_PLAN.md`.

## ⚠ One conflict to reconcile

`BACKEND_DEVELOPMENT_PLAN.md` §3 (the earlier, hand-written master plan) recommends **NestJS/Node + Prisma**. This workflow independently decided **Laravel + Filament**. They disagree. Treat **[01-tech-stack-decision.md](01-tech-stack-decision.md)** (the scored ADR) as authoritative going forward, and either update `BACKEND_DEVELOPMENT_PLAN.md`'s stack section to match or retire it in favour of the numbered doc set.

## Full deliverables

[README.md](README.md) · [memory.md](memory.md) · [00-executive-summary](00-executive-summary.md) · [01-tech-stack-decision](01-tech-stack-decision.md) · [02-architecture](02-architecture.md) · [03-data-model](03-data-model.md) · [04-api-contract](04-api-contract.md) · [05-security-and-compliance](05-security-and-compliance.md) · [06-devsecops-pipeline](06-devsecops-pipeline.md) · [07-testing-strategy](07-testing-strategy.md) · [08-environments-and-runbooks](08-environments-and-runbooks.md) · [phases/](phases/README.md)
