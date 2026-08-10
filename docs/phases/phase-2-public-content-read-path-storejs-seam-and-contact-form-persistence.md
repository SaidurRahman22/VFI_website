# Phase 2 — Public Content Read Path, store.js Seam & Contact-Form Persistence

**What this is:** the build sheet for serving all 32 public marketing pages from the API (read-only) via a single per-page content bundle, completing the `js/store.js` migration for public content, and stopping the silent discard of real contact leads. **Who it's for:** the backend/API dev and the frontend-seam dev.

See [phases/README.md](README.md) for the gate model, [memory.md](../../memory.md) for the render hooks and store API this serves, and [BACKEND_DEVELOPMENT_PLAN.md](../BACKEND_DEVELOPMENT_PLAN.md) for the content data-model detail.

| | |
|---|---|
| **Goal** | Serve the 32 public pages from `GET /content/bundle` with the empty-means-fall-through contract intact; persist contact-form leads to a staff inbox. |
| **Duration** | 3–4 weeks |
| **Depends on** | [P1](phase-1-identity-spine-and-admin-lockdown.md) (identity spine + Filament shell for the staff inbox view). Touches disjoint tables/routes from P4, so **safe to overlap P4**. |
| **Blocks** | P3 (the admin write path edits what this reads). |

> **Why the contact form is a P0 emergency.** `contact.html`'s submit handler `preventDefault`s, waits 600 ms and shows a success panel — the lead is thrown away. It is the only public write path on the marketing site and it is not in the store. If the site is deployed anywhere public, real enquiries are being lost right now.

---

## The two load-bearing content semantics

Both must survive the move to a server or 32 pages visibly break.

| Semantic | Rule | Failure if violated |
|---|---|---|
| **Empty means fall through** | Every text field seeds `""`, every list seeds `[]`. `render.js`/`portal-render.js` overwrite the DOM **only** when the stored value is non-empty; otherwise the hand-written HTML stands. | An API that returns nulls, defaults, or backfills seed values blanks or duplicates large sections of 32 pages. `null` and `""` are **not** interchangeable. |
| **Order is the array** | `VFI.put()` unshifts to the front; `render.js` takes `.slice(0,3)` for the home featured trio and `blogs[0]` is the featured card. No sort key exists today. | Without an explicit `position` column, the home page is non-deterministic in SQL. |

The disabled `ConvertEmptyStringsToNull` + `TrimStrings` middleware from [P0 step 9](phase-0-platform-and-delivery-foundation.md) **must actually be off** on these routes.

---

## In scope

1. **10 stable content collections** as relational tables (`events`, `blogs`, `news`, `photos`, `ppManagers`, `ppUpdates`, `ppQuicklinks`, `ppDocs`, `ppEmails`, `ppNotifs`) with explicit `position` INTEGER (new-to-front) and `legacy_id` preserved. `blog_posts.legacy_id` **is** the public URL — verbatim.
2. **5 override singletons** (`countries` / `regions` / `servicesPage` / `partnerPage` / `partnerConsoleText`) as jsonb + a `version` column; `media{key→imgId}` and `pages{file→bool}` maps; `taxonomy_terms` lookup seeding.
3. `GET /content/bundle?page=` returning the per-page bundle; `store.js` accessors stay **SYNCHRONOUS** reading `window.VFI_BOOTSTRAP` injected before `store.js`.
4. `getImage()` dual-mode preserved server-side: path/URL-shaped ids pass through (bundled `assets/img/*.jpg` defaults keep working); real image ids resolve to CDN URLs.
5. Idempotent Artisan importer from the existing `VFI.exportAll` JSON.
6. Public read endpoints GET-only, ETag/Last-Modified, CDN-cacheable with purge-on-publish; dates stored as **DATE not timestamptz**.
7. `contact_enquiries` table + `POST /contact` with Turnstile + rate limiting + URL/scheme sanitation; a Filament read-only inbox view.

## Out of scope

- Any content **WRITE/CRUD** from admin ([P3](README.md)) — content is edited only via the importer/seed for now.
- Image **UPLOAD** pipeline (P3). Images are served read-only from the imported set.
- Newsletter subscribe wiring; student/partner data.

---

## Content model summary

| Kind | Storage | Ordering |
|---|---|---|
| 10 collections (`events`, `blogs`, `news`, `photos`, `pp*`) | Relational tables, stable columns | Explicit `position` INTEGER, new item to front |
| 5 override singletons (`countries`, `regions`, `servicesPage`, `partnerPage`, `partnerConsoleText`) | jsonb + `version` | Position lives inside each override list, imported from array index |
| `media` (key→imgId) | Map table / jsonb | — |
| `pages` (file→bool) | Map | Absent key = ON (only exactly `false` disables) |

Dates: `events`/`blogs` store `date` as **DATE** — a `timestamptz` renders 2026-09-02 as 01 Sep for a Dhaka viewer at UTC+6.

---

## Work breakdown

### 1. Collections schema
1.1 Ten tables with stable columns per the SEED shapes in [memory.md](../../memory.md) (`js/store.js` SEED). Each gets `position` INTEGER and `legacy_id`.
1.2 `blog_posts.legacy_id` = the current `uid()` value verbatim (e.g. `b_lx8f2k3`) — this is the `blog-post.html?id=` route key. Preserving it verbatim is non-negotiable.
1.3 Seed `taxonomy_terms`.

### 2. Override singletons
2.1 Five jsonb columns + `version` (for P3 optimistic concurrency). Free-form shapes matching the current objects.
2.2 `media` and `pages` maps.

### 3. The bundle endpoint
3.1 `GET /content/bundle?page=<file>` returns everything that page's `render.js` appliers need in one response (avoids ~90 individual media lookups).
3.2 The response round-trips `""` / `[]` **faithfully** — never substitutes defaults.
3.3 Inject as `<script>window.VFI_BOOTSTRAP={…}</script>` **before** `store.js` so the synchronous accessors (`list get settings media country region servicesPage partnerPage pageEnabled`) stay synchronous.

### 4. Image resolution (dual-mode)
4.1 `getImage()` server-side: an id that looks like a path/URL (contains `/`, starts `http(s):`, or ends `.jpg/.png/.webp/.svg/.gif/.avif`) resolves to itself — that is how the bundled `assets/img/*.jpg` SEED defaults work.
4.2 A real image id resolves to a content-hashed immutable CDN URL (1-year cache). Painted as a CSS `background-image` string exactly as today — switching from data URL to URL is transparent to every call site.

### 5. Importer (idempotent)
5.1 Artisan command over the existing `VFI.exportAll` JSON: upsert on `legacy_id`, remap `img_*` ids to R2 images, inject array-index as `position`, pass through path-style asset refs.
5.2 Re-runnable: a second run produces no duplicates and no id churn.

### 6. Caching + dates
6.1 Public GETs: GET-only, ETag/Last-Modified, CDN-cacheable, purge-on-publish, `Vary: Origin`, **no `Set-Cookie`**.
6.2 Dates stored as DATE, formatted client-side (`toLocaleDateString('en-US')`) as today.

### 7. Contact form (the P0)
7.1 `contact_enquiries` — `fname`, `phone`, `email`, `dest`, `msg`, `submitted_at`, `source_page`, `ip`, `status`.
7.2 `POST /contact` — Turnstile, per-IP + per-email rate limits, size caps, **never accepts a partner/student id**.
7.3 Filament read-only inbox view; output-encoded.
7.4 Wire `contact.html #cform` (`js/main.js` ~line 421) to the real endpoint; success panel reflects a real 202.

### 8. URL-scheme hardening
8.1 Server-side allow-list (http / https / mailto / relative) on `ppQuicklinks.url`, `ppDocs.url`, `services_blocks.ctaHref` **before** they reach any `href`. A `javascript:`/`data:` value is refused. (`VFI.esc()` escapes HTML but does not restrict URL schemes.)
8.2 Blog body kept **plain-text end-to-end** (server validates; no HTML) — preserves the `articleHTML()` anti-stored-XSS contract.

---

## Deliverables

- All 32 public pages rendering from `GET /content/bundle` with empty-string/`[]` fall-through intact (no blanked or duplicated sections).
- Importer that reproduces the current demo content on staging from a real backup file.
- `contact.html` form persisting leads to the DB + a staff-visible inbox; success panel now reflects a real 202.
- Image delivery via content-hashed immutable CDN URLs with 1-year cache; path-style defaults still resolve.

---

## Security work

| Item | Detail |
|---|---|
| URL scheme allow-list | http/https/mailto/relative enforced server-side on `ppQuicklinks.url`, `ppDocs.url`, `services_blocks.ctaHref` before any `href` |
| Blog body | plain-text end-to-end (server validates, no HTML) — anti-stored-XSS contract preserved |
| Contact endpoint | Turnstile, per-IP + per-email rate limits, size caps, no partner/student id accepted, output-encoded in the inbox |
| Public read routes | no auth data leakage, no PII, `Vary: Origin`, CDN-cache-safe (no `Set-Cookie` on public GETs) |

---

## Testing

| Test | Passes when |
|---|---|
| Fall-through contract | A blank override field/list leaves the page's built-in HTML standing; a non-empty one overrides — asserted for country/region/services/partner/settings |
| Ordering | Put-to-front reproduces the home featured event trio and `blogs[0]` featured card deterministically |
| Importer round-trip | export JSON → import → bundle output matches the seeded demo for all 32 pages; blog `legacy_id` URLs resolve |
| Contact happy path | A submission persists and appears in the staff inbox |
| Contact abuse | Spam/rate-limit path rejected; a `javascript:` URL in any content field is refused |

---

## Exit gate

- [ ] All 32 public pages render correctly from the API with the empty-means-keep-built-in-HTML contract verified by automated tests.
- [ ] `blog-post.html?id=<legacy_id>` resolves for every imported post (no broken shared links).
- [ ] A real contact submission lands in the DB and appears in the staff inbox; the old silent-discard path is removed.
- [ ] A `javascript:`/`data:` URL in a content URL field is rejected server-side; blog HTML is stored/rendered as plain text.
- [ ] Public GETs are CDN-cacheable (ETag present, no `Set-Cookie`) and images serve from immutable hashed URLs.

Two-party sign-off per [README](README.md#gate-model).

---

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Empty-string semantics are load-bearing across 32 pages — a null/default substitution silently breaks sections | The P0 middleware disable must actually be off on these routes; the fall-through contract test asserts it per override family |
| Sync accessors depend on the bundle being injected **before** `store.js` | Guard page load order; a regression test asserts `VFI_BOOTSTRAP` exists before `store.js` runs |
| Importer regenerating ids breaks every shared/indexed link | Upsert on `legacy_id` verbatim; the round-trip test resolves every blog URL |
| Path-style SEED assets accidentally treated as image ids | `getImage()` dual-mode test covers both a real id and a path-style id |

---

## Frontend files / REAL REQUEST markers wired

No `js/auth.js` / `js/partner-auth.js` markers this phase.

| File | Change |
|---|---|
| `contact.html` / `js/main.js` (~line 421) | `#cform` wired to `POST /contact` — an **unmarked write path**, now real. |
| `js/store.js` | Content accessors switch from the local blob to the injected `VFI_BOOTSTRAP` bundle across all public pages — invisible to users. |

Per-page demo-disclaimer removal for content surfaces begins in [P3](README.md) once real editing backs them; the contact success panel now reflects a real submission.

**Previous:** [Phase 1 — Identity Spine & Admin Lockdown](phase-1-identity-spine-and-admin-lockdown.md) · **Next:** Phase 3 — Admin CMS Write Path.
