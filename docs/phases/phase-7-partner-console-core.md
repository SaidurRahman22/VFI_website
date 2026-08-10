# Phase 7 (P7) — Partner Console Core

**What this is:** the build spec for the authenticated partner console's core data surfaces — students, the applications pipeline, enquiries with document intake, and QR referral attribution. **Audience:** the backend/API dev and the frontend-seam dev wiring `js/portal.js`.

> Everything here is tenant-scoped. It cannot start until [Phase 6](phase-6-partner-identity-and-tenancy.md)'s tenancy-isolation hard gate is green. The applications pipeline is **greenfield**, not a port — `partner-applications.html` is a 3 KB placeholder today.

Related docs: [Phase 6 — Partner Identity & Tenancy](phase-6-partner-identity-and-tenancy.md) · [Phase 5 — Student Portal](phase-5-student-portal.md) (document pipeline reused) · [Phase 8 — Program Search](phase-8-program-search.md) · [Phase 9 — Money & Launch](phase-9-money-surface-staff-backoffice-and-launch.md) · [Backend master plan](../BACKEND_DEVELOPMENT_PLAN.md) · [agent orientation](../../memory.md)

---

## Goal

Make the 11-page console real and tenant-scoped: register/list students, build the applications pipeline that drives the 8 dashboard KPIs, wire the enquiry + program-request modals with real scan-gated document intake, and ship QR referral attribution — all behind a hard console guard on every static `partner-*.html` page.

## Duration

5–6 weeks.

## Prerequisites

| Needs | From | Why |
|---|---|---|
| Tenancy net (scope + RLS + CI test) green on real partner tables | [Phase 6](phase-6-partner-identity-and-tenancy.md) | Every read/write here is tenant-scoped; this is the load-bearing dependency |
| Partner sign-in, session, `/api/session/me`, real logout | [Phase 6](phase-6-partner-identity-and-tenancy.md) | The console guard calls `/api/session/me`; agency id comes from the session |
| Document upload pipeline: multipart → magic-byte → ClamAV scan-gate → private R2 → single-use presigned GET → `document_access_log` | [Phase 5](phase-5-student-portal.md) | Enquiry academic docs reuse it byte-for-byte |
| Content-bundle seam (`js/store.js` HTTP client, `window.VFI_BOOTSTRAP`), `js/api.js` | [Phase 2](phase-2-public-content-read-path.md) / [Phase 1](phase-1-identity-spine-and-admin-lockdown.md) | Console pages already load through the seam |
| **Attribution-collision policy resolved** (self-signup vs partner-registered vs QR) | Client decision raised at Phase 6 sign-off | Attribution maps directly to commission; must be settled before any write here |

## Wiring order caveat (do this first)

`js/portal.js:430` is a **single shared submit handler for BOTH global modals** (Register New Student and Request Program Options). **Split it per form before adding any network call** — wiring it wrong breaks student registration and enquiry submission at once.

---

## In scope

- Console page guard on every `partner-*.html`; real logout.
- `students` (agency-scoped): create from the modal, paged/filtered list, archived list.
- `applications` + `application_status_events` (append-only): the pipeline table, tenant-scoped 8-KPI aggregates and deadline buckets computed server-side.
- `program_requests` + `program_request_documents`: enquiry modal with real academic-doc upload; enquiries list.
- `agency_referral_links` (opaque slug, revocable, rate-limited) + a real QR image + a public QR-registration landing page.
- Learning Resources filtering as a real server query; notifications list + read-state with a shared source for the bell popover.

## Out of scope (explicit)

| Not here | Where |
|---|---|
| Wallet/money, application-fee debit, Flywire | [Phase 9](phase-9-money-surface-staff-backoffice-and-launch.md) — `applications.create` writes the row; the fee debit is flag-gated OFF here |
| Program search (100k catalogue) | [Phase 8](phase-8-program-search.md) — the search page stays as-is until then |
| AI assistant, AI mock interview, allied/loan services | [Phase 9](phase-9-money-surface-staff-backoffice-and-launch.md) and beyond — Pennant-flagged OFF |
| Application **status writes** by staff (advance to Offer/Visa/etc.) | [Phase 9](phase-9-money-surface-staff-backoffice-and-launch.md) staff back-office — P7 builds the read pipeline + create; transitions are staff-authored later |
| Commission ledger | [Phase 9](phase-9-money-surface-staff-backoffice-and-launch.md) |
| Student detail/edit page in the console | Deferred — list + create only in P7 |

---

## Work breakdown

### 1. Console guard (all 11 `partner-*.html`)

- Each page calls `GET /api/session/me` on load; on 401 it **redirects to `vfi-partner-login.html` and renders nothing first** — the pages are static files and cannot self-protect; the API is the only guard.
- Add `<meta robots noindex>` + `Cache-Control: no-store` posture for authed content.
- Wire `js/portal.js:313 logout()` (already done in Phase 6) and the sidebar `#ppLogout` link (which today is a bare `<a>` that never calls `logout()`).

### 2. Split the shared modal handler

- Split `js/portal.js:430 wireModalForm` into two handlers:
  - `#ppRegForm` → `POST /api/partner/students`
  - `#ppProgForm` → `POST /api/partner/enquiries` (multipart, with `#ppProgFiles`)
- Keep each independent: a failure in one must not break the other.

### 3. Students (tenant-scoped)

| Concern | Detail |
|---|---|
| Create | From `#ppRegForm` (first/middle/last, dial code + mobile, email). **`partner_agency_id` from session — never from the form or a URL.** |
| Duplicate/claim rule | If the email already belongs to a student owned by **another** agency, refuse (or route to the agreed claim/transfer flow). Never silently re-parent — that hijacks attribution and commission. |
| List | `GET /api/partner/students` — paged, filtered by created-date range, destination country, intake, year, status, free-text keyword (shape from `partner-students.html` filters). |
| Archived | `GET /api/partner/students?archived=1` (soft-delete via `archived_at`). |

Tables: `students` (with denormalised `partner_agency_id`, `source` enum `self_signup\|partner_modal\|qr_link\|admin`, `registered_by_user_id`, `public_ref`).

### 4. Applications pipeline (greenfield)

`partner-applications.html` is a placeholder — design the pipeline from scratch, scope generously.

| Table | Key columns |
|---|---|
| `applications` | `id`, `partner_agency_id`, `student_id`, `program_id?`, `institution_id?`, `intake_month`, `intake_year`, `status`, `ack_no`, `submitted_at?`, `deadline_at?`, `deferred_to_intake?`, `created_at` |
| `application_status_events` | `id`, `application_id`, `from_status`, `to_status`, `occurred_at`, `actor_type` (`partner\|staff\|system\|institution`), `actor_id`, `note` — **append-only** |

- Status vocabulary (native PG enum) from the 8 KPI cards + `partner-students.html` select: `submitted`, `review`, `offer`, `conditional`, `payment`, `visa_received`, `visa_rejected`, `non_enrolment`, `deferral`, `pending_from_partner`.
- `applications.create`: create the row (fee debit is deferred — call the Phase 9 debit **behind a Pennant flag, OFF**).
- **KPIs computed server-side, tenant-scoped:** `GET /api/partner/dashboard/kpis?from&to&intake&year&country` returns the 8 counters as `GROUP BY status` over the tenant + date range. Never client-filter from a global list.
- Deadline buckets: `GET /api/partner/dashboard/deadlines` → today / tomorrow / 7 days / 14 days derived over `deadline_at`.

### 5. Enquiries + program-request documents

| Table | Key columns |
|---|---|
| `program_requests` | `id`, `partner_agency_id`, `created_by_user_id`, `enquiry_type` (`new\|existing`), `student_id?`, name fields, `country_of_education`, `highest_education_level`, `destination`, `preferred_study_area`, `preferred_study_level`, `program_label?`, `additional_info?`, `channel` (`console\|whatsapp`), `status`, `created_at` |
| `program_request_documents` | `id`, `program_request_id`, `file_id`, `original_filename`, `content_type`, `size_bytes`, `scan_status`, `uploaded_by`, `uploaded_at` |

- The `existing` branch must resolve the student **within the acting tenant only**.
- Files (`#ppProgFiles`, `.pdf/.jpg/.png`, multiple) go through the exact Phase 5 pipeline: magic-byte sniff, ClamAV scan-gate (unreadable until clean), private R2, single-use presigned GET, access log. These are transcripts — sensitive personal data.
- `GET /api/partner/enquiries` list.
- The WhatsApp button (`js/portal.js:216`) has no handler today; decide `wa.me` deep link (cheap) vs deferred — out of scope to wire the API here, but do not leave it silently broken.

### 6. QR referral attribution

| Table | Key columns |
|---|---|
| `agency_referral_links` | `id`, `partner_agency_id`, `slug` (opaque, unguessable), `created_by_user_id`, `revoked_at?`, `max_uses?`, `uses_count`, `last_used_at?` |
| `referral_signups` | `id`, `referral_link_id`, `student_id`, `ref_code_seen`, `landed_at`, `converted_at?`, `channel` (`qr\|link`) |

- `GET /api/partner/referral-link` returns the opaque slug + a **real** QR image from the API (the current QR is a hand-drawn decorative `<svg>` encoding nothing; `partner-dashboard.html:42–64`).
- **Fix the QR target:** today `#ppCopyLink` points at `vfi-partner-login.html?ref=hakim` (the partner login). Point it at a new public student-registration landing page.
- Public QR-registration landing page: an **unauthenticated write that sets tenancy**. Guards: slug validation, revocation check, per-slug rate limit, Turnstile, and **email verification before the attribution counts**.
- Wire `?ref=` capture into the registration path (today ignored by `js/auth.js` and `js/partner-auth.js`).

### 7. Console content fixes

- **Learning Resources filtering is fake today** — `portal-render.js R.docs` renders every `ppDocs` row regardless of the selected country/category, the two left panels only toggle a CSS class, and `#resSearch` has no handler. Replace with a real server query `GET /api/partner/resources?country=&category=&q=`.
- Notifications: `GET /api/partner/notifications` (paged, read/unread) + `POST .../read`. The bell popover (`js/portal.js NOTIF_POP`) is a hard-coded "No notifications found" — point it at the same source as the notifications page.

---

## Deliverables

- Guarded, tenant-scoped console: real student CRUD, a working applications pipeline with live server-computed KPIs, enquiries with scanned document upload.
- Working revocable referral link + real scannable QR + a public registration landing page with correct attribution.
- Notifications with real read/unread; Learning Resources filtered by a real query.
- `js/portal.js:430` split into two correctly-wired form handlers.

## Security work

| Item | Control |
|---|---|
| Tenant scoping | All reads/writes scoped to the session agency via the shared layer + RLS; KPIs computed server-side per tenant, never client-filtered |
| Enquiry document intake | Magic-byte + ClamAV + private storage + single-use presigned retrieval + access log (transcripts are sensitive) |
| QR/referral | Opaque slug (no raw agency id in URL), revocation, per-slug rate limit, Turnstile, email verification before attribution counts — prevents commission farming |
| Duplicate/claim | Enforced so one agency cannot silently re-parent another's student |
| URL-scheme allow-list | On any partner-authored URL field (http/https/mailto/relative) |
| Console pages | `noindex` + `no-store`; render nothing until `/api/session/me` resolves |

## Testing

| Test | Asserts |
|---|---|
| Tenancy | Student/application/enquiry lists and KPIs are provably scoped; a forged agency id in body/query changes nothing |
| KPI correctness | A status transition writes exactly one append-only event and moves exactly the right KPI counter |
| Enquiry upload | Scan-gate + signed retrieval give the same guarantees as student docs; an EICAR file is quarantined and never served |
| Referral | Guessing/altering a slug fails; a revoked slug rejects registration; attribution only counts after email verification |
| Modal split | Student registration and enquiry submission are independent (one failing does not break the other) |
| Resources filter | Selecting a country/category returns only that set (no dump-everything) |
| Guard | Console pages render no data before `/api/session/me` resolves and redirect on 401 |

---

## Exit gate

- [ ] A signed-in partner can register a student, see a tenant-scoped applications pipeline whose 8 KPIs are computed server-side, and submit an enquiry with a scanned document — all impossible to read cross-tenant (negative test green).
- [ ] Console pages render nothing until `/api/session/me` resolves and redirect on 401.
- [ ] The QR link uses an opaque revocable slug, generates a real (scannable) QR, and a student registering through it is attributed only after email verification.
- [ ] A cross-agency duplicate student cannot be silently re-parented (claim policy enforced by test).
- [ ] Learning Resources returns only the selected country/category, and the notification bell shares the notifications data source.
- [ ] `js/portal.js:430` is split into two handlers; the console's single REAL REQUEST marker is wired for both.
- [ ] No BLOCK-class CI gate red (secrets, CVE, SAST-high, tenancy test, licences).

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Applications pipeline is greenfield, not a port | Scope generously (5–6 weeks); design status vocabulary + events table up front; do not assume the demo's 6-application shape is a limit |
| Attribution collisions map directly to money | The claim/merge rule must be settled at Phase 6 sign-off before this ships; enforce it in `students.create` and the QR path |
| Shared `portal.js:430` handler breaks both modals | Split first, wire second — non-negotiable ordering |
| KPIs client-filtered by habit | All 8 counters + deadline buckets are server-side aggregates; add a test that a client cannot alter them |
| QR self-registration is an unauthenticated tenancy write | Turnstile + rate limit + email-verify-before-attribution + opaque slug; treat as the highest-risk write on the console |

## Frontend wiring (exact files/pages)

| File / page | Change |
|---|---|
| `js/portal.js:430` | **Split** the shared handler; wire `POST /api/partner/students` and `POST /api/partner/enquiries` (multipart) |
| `js/portal.js:313` + sidebar `#ppLogout` | Real logout endpoint; fix the sidebar link that never calls `logout()` |
| `js/portal.js NOTIF_POP` / `#ppBell` | Bell popover reads the real notifications source |
| `js/portal-render.js R.docs` | Learning Resources renders the server-filtered set, not every row |
| all `partner-*.html` | Add the `/api/session/me` guard on load; render nothing pre-auth |
| `partner-dashboard.html` `.pp-stats`, `.pp-tabs`, `#ppApplyFilters` | Wire the 8 KPIs + deadline buckets to server aggregates |
| `partner-students.html` `#ppStuSearch`, `#ppArchived`, filters | Wire the paged/filtered list + archived list |
| `partner-applications.html` | Build the pipeline table + filters (greenfield) |
| `partner-enquiries.html` | Wire the enquiries list; the "new enquiry" action re-opens the modal |
| `partner-resources.html` `#resSearch`, `#resCountries`, `#resCategories` | Wire the real filter query |
| `partner-notifications.html` `[data-ppr="notifs"]` | Wire real read/unread |
| `partner-dashboard.html` `#ppCopyLink`, QR block | Real referral slug + real QR; fix the target to the new student-registration page |
| **New public QR-registration page** | Turnstile + email-verify + attribution; `?ref=` capture wired into the registration path |
