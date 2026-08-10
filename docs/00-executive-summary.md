# 00 — Executive Summary

One-page overview of the VFI backend build: what it is, what it costs, what could go wrong, and what to do first. For the client/product owner **and** the tech lead. Full rationale in [Tech-stack decision](01-tech-stack-decision.md).

---

## What we are building

VFI Overseas Education is today a **browser-only static demo**: 52 hand-written HTML pages, vanilla ES5 JS, no build step, no server. All "data" lives in the visitor's own browser (`localStorage` + `IndexedDB`), so nothing is saved, shared, or secured. The admin panel has **no login at all**, and the contact form **silently throws away every real enquiry**.

We are adding a real backend — a separate service the static pages call over HTTP — that turns the demo into an operating system across five surfaces:

| Surface | What it becomes |
|---|---|
| Public marketing site (32 pages) | Server-backed CMS: events, blogs, news, gallery, per-country/region pages, settings, page on/off |
| Admin panel | Authenticated CMS with roles, audit log, media pipeline, backup/restore |
| Student auth + portal | Real sign-in, email OTP, password reset, profile, document uploads (passports, transcripts, bank statements), application tracking |
| Partner auth + console (11 pages) | Multi-tenant agency console: students, applications, wallet/money ledger, 100k-program search, enquiries |
| Staff back-office | The authoring side that does not exist today: agency review, document verification, application status |

**Hard constraint that never changes:** the front end stays vanilla HTML/CSS/JS with no build step. The backend is a separate codebase. The 52 pages migrate almost untouched behind the `js/store.js` HTTP-client seam.

---

## Recommended stack

| Layer | Choice | One-line justification |
|---|---|---|
| Language / framework | **PHP 8.3 + Laravel** | Deepest, cheapest, most **replaceable** hire in Dhaka; ships the auth/security plumbing this product is mostly made of |
| Admin panel | **Filament v4** | Maps ~1:1 onto the existing `js/admin.js` SCHEMA; adds login, roles, audit, reorder — closing the top security gap in milestone one |
| Database | **Managed PostgreSQL 16 + PITR** | Money ledger needs ACID; tenancy needs Row-Level Security; the 5 schema-fluid CMS blobs go in `jsonb` — one engine, both jobs |
| Auth | **Sanctum cookie sessions** (HttpOnly, SameSite=Lax) | Same-origin behind one nginx → no CORS, no token in `localStorage` (safe against the existing `innerHTML` XSS surface) |
| Object storage | **Cloudflare R2**, two buckets | Public content-hashed images via CDN; private scan-gated documents with presigned GETs and an access log |
| Cache / jobs | **Redis + Horizon** | Sessions, rate-limit/OTP counters, queue backend; four named queues so an AV scan never delays an OTP |
| Search | **Postgres-first** (flat table + GIN tsvector + `smallint[]` facets) | 300–600k rows is small; add Typesense only if live per-facet counts are ever demanded |
| Email | **Postmark → SES** | OTP/reset deliverability is the product's front door; SPF+DKIM+DMARC on a dedicated subdomain |
| Hosting | **One 4GB VPS (Singapore/Mumbai)** + managed Postgres + Cloudflare | ~40–70ms from Dhaka; provisioned by Forge for a no-ops team |

Full comparison of the five candidate stacks (Laravel, Django, .NET, NestJS, FastAPI) with judge scores in [Tech-stack decision](01-tech-stack-decision.md).

---

## Phases and timeline

**10 phases (P0–P9).** MVP = P0–P6 (foundation, admin lockdown, public CMS, student portal, partner tenancy). Everything else (search, money, staff back-office) follows.

| Phase | Focus | Duration |
|---|---|---|
| **P0** | Platform & delivery foundation | 2–3 wk |
| **P1** | Identity spine & **admin lockdown** (P0 emergency) | 3–4 wk |
| **P2** | Public content read path + **contact-form persistence** (P0 emergency) | 3–4 wk |
| **P3** | Admin CMS write path (Filament) | 4–5 wk |
| **P4** | Student identity — register, OTP, password reset | 3–4 wk |
| **P5** | Student portal — profile, documents, tracking | 5–6 wk |
| **P6** | Partner identity & **tenancy isolation** (hard gate) | 4–5 wk |
| **P7** | Partner console core — students, applications, enquiries | 5–6 wk |
| **P8** | Program search — 100k catalogue, 40-facet query | 4–6 wk |
| **P9** | Money surface, staff back-office, production launch | 5–7 wk |

Rough totals with 3 developers: **first secure release (P0–P2) ~8–11 weeks**; **MVP (P0–P6) ~5–6 months**; **everything the current HTML promises ~9–12 months**. The 11 `REAL REQUEST` markers are ~a day each; the content layer has *zero* markers and is ~40% of the wiring — anyone scoping from the markers is wrong by ~5×.

---

## Top risks and mitigations

| Risk | Mitigation |
|---|---|
| **Admin panel has no auth today.** Wiring it to a server before login exists publishes an unauthenticated write endpoint over the whole database — worse than the demo | Ship admin login + mandatory TOTP in **P1, before any admin content endpoint exists** (hard gate) |
| **Tenant leak:** one forgotten `WHERE` exposes a competitor's entire student book | Eloquent global scope (agency id from session only) **+ Postgres RLS as a second net + a CI test** that fails on any untenanted partner query (P6 hard gate) |
| **Sensitive-document custody** (passports, bank statements, police clearances) | Private bucket, server-generated keys, ClamAV scan-gate before readable, per-request 60–300s presigned GET, append-only access log (P5) |
| **OTP is brute-forceable / email amplification** (any 6 digits pass; cooldowns are client-only) | Server-side per-email + per-IP rate limits; hashed, TTL'd, attempt-capped OTP bound to an opaque `flow_id` (P4/P6) |
| **Partner email-change is an account-takeover vector** as written | Bind codes to a server-side pending-registration record keyed by `flow_id`; changing address restarts the flow (P6) |
| **Program catalogue has no data source** anywhere in the repo | Treat P8 as "search **+ ingest**"; ingest is blocked until the client names the feed/licence — no stack choice fixes this |
| **Money correctness** (wallet, commission) | Append-only ledger with `balance_after`, idempotency keys, atomic fee-debit-with-application under a serialisable transaction; nightly reconciliation (P9) |
| **Managed Postgres is single-node** at this budget | Rehearse the PITR restore quarterly, or the backups are theatre |

---

## Rough monthly running cost

Infrastructure only. Developer time and third-party AI/PSP fees dwarf every line below.

| Item | Monthly |
|---|---|
| App VPS (4GB, Singapore/Mumbai) | $24 |
| Managed Postgres (PITR, single node) | $30 |
| Redis (on the app box) | $0 |
| Cloudflare R2 (~100GB docs + images, zero egress) | $3 |
| Postmark (10k transactional emails) | $15 |
| Cloudflare (free tier) | $0 |
| Backups, domain, misc | $5 |
| Forge provisioning | $12 |
| **MVP subtotal** | **~$40–90** |
| + staging environment | +$25–50 |

**Excluded and genuinely unbounded:** LLM spend (the assistant + AI mock interview are usage-priced — gate with an atomic quota decrement **before** each model call) and PSP fees (bKash/Nagad/SSLCommerz take ~1.5–2.5% per top-up). Infrastructure is not where this project's money goes.

---

## Top security concerns (passports, bank statements, money)

1. **Close the admin hole first.** No admin content endpoint ships before login + TOTP (P1).
2. **Tenant isolation is default-deny in two layers** (Eloquent scope + RLS), proven by a CI test.
3. **Documents:** private bucket, scan-gate, presigned reads, append-only `document_access_log` — "who opened whose passport, when" must be answerable.
4. **No token in `localStorage`.** HttpOnly cookie sessions, because the codebase builds `innerHTML` from untrusted strings.
5. **GDPR is architectural, not a feature.** Bangladeshi passports/bank statements shared with UK/EU/AU institutions bring UK/EU GDPR obligations: subject export, erasure with a legal-hold exception, per-document retention clock, record of onward disclosure — designed into the schema from the start.
6. **URL-scheme allow-list** on admin-authored `href` fields (`ppQuicklinks.url`, `ppDocs.url`, `services_blocks.ctaHref`); keep the blog body **plain-text end-to-end** to avoid stored XSS.

---

## Immediate next three actions

1. **Confirm scope + fund the P0 emergency.** Decide what is actually being built (marketing site + admin, or the full console/wallet/AI). Then greenlight P0–P1: platform foundation **and admin authentication**, because the live site today has an unauthenticated admin panel and a contact form that discards real leads.
2. **Provision the platform.** Stand up the managed Postgres+PITR instance, Redis, the two R2 buckets, and one same-origin nginx box (Forge). Prove the same-origin topology now — it is the load-bearing assumption behind the whole cookie-auth design.
3. **Name the program-catalogue data source.** Search (P8) is blocked on data, not code. Identify the feed, refresh cadence, and licence before it is scheduled — this is the single largest unscoped item and no engineering decision touches it.

---

See also: [Tech-stack decision (ADR-001)](01-tech-stack-decision.md).
