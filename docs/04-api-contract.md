# 04 — Backend API surface (v1)

What this is: the definitive v1 HTTP contract for the VFI backend — global conventions, per-module endpoint tables, an OpenAPI skeleton, and the map from each frontend `REAL REQUEST` marker to its real endpoint. For the engineers and AI agents wiring `js/store.js`, `js/auth.js`, `js/partner-auth.js`, `js/portal.js` and Filament. Sibling docs: [Architecture](02-architecture.md), [Data model](03-data-model.md), frontend orientation in [../memory.md](../memory.md).

**Stack:** PHP 8.3 · Laravel · Sanctum (cookie sessions) · PostgreSQL 16 (managed, PITR) · Redis · Cloudflare R2 · Filament v4 admin.
**Topology:** static site and API are served **same-origin** behind one nginx. Static files at `/`, API at `/api/v1/*`, Filament admin at `/admin` (server-rendered; only its `/api/v1/admin/*` JSON routes are covered here).

> **Assumptions** (flagged throughout with `⚑`):
> - `⚑A1` Same-origin deployment is the launch topology. All cookie/CSRF design below depends on it. A cross-origin split is out of scope for v1 and would force `SameSite=None; Secure` + credentialed CORS.
> - `⚑A2` The brief says "8 REAL REQUEST markers." The repo actually contains **11** (4 in `js/auth.js`, 6 in `js/partner-auth.js`, 1 in `js/portal.js`). This spec maps **all** of them — see §3.1. The "8" was informal; treat 11 as authoritative.
> - `⚑A3` `/api/v1` is prefixed onto the paths the stub code currently posts to (e.g. stub `POST /api/login` → real `POST /api/v1/auth/student/login`). The `js/store.js`/`js/api.js` rewrite absorbs this; stub comments are illustrative, not contractual.

---

## 1. Global conventions

### 1.1 Versioning & base path

| Item | Value |
|---|---|
| Version prefix | `/api/v1` (path-based; bump to `/api/v2` only on breaking change) |
| Content type | `application/json; charset=utf-8` for all bodies except uploads (`multipart/form-data`) |
| Trailing slash | Not accepted; canonical paths have no trailing slash |
| Unknown fields in request | **Ignored** (forward-compatible); never `400` on extra keys |
| Casing | `snake_case` for all JSON keys and query params |
| Time over the wire | ISO-8601 UTC (`2026-08-09T14:03:22Z`) for timestamps; **plain `YYYY-MM-DD`** for `date`-typed fields (events, blogs, DOB, intakes) — see §1.11 |

### 1.2 Auth token strategy (end-to-end)

Laravel **Sanctum SPA/cookie mode**. **Nothing sensitive is stored in JS-reachable storage** (no token in `localStorage`/`sessionStorage`) — deliberate, given the existing `innerHTML` XSS surface (`js/portal.js` `VFIToast`).

Cookies the browser holds (all server-set):

| Cookie | Scope path | Flags | Lifetime |
|---|---|---|---|
| `vfi_session_student` | `/api/v1` | `HttpOnly; Secure; SameSite=Lax` | idle 30 m (7 d if "remember"); absolute 12 h (7 d remember) |
| `vfi_session_partner` | `/api/v1` | `HttpOnly; Secure; SameSite=Lax` | idle 30 m (7 d remember); absolute 12 h (7 d remember) |
| `vfi_session_admin` | `/api/v1/admin` | `HttpOnly; Secure; SameSite=Strict` | idle **15 m**; absolute 8 h |
| `XSRF-TOKEN` | `/` | `Secure; SameSite=Lax` (**readable by JS** — that is its job) | session |

- Three cookie **names/scopes** so a student session can never act on partner/admin routes and vice-versa. Each `sessions` row records `user_id`, `active_role`, `active_partner_agency_id`, `absolute_expires_at`, `idle_expires_at`, `revoked_at`.
- **No JWT.** Opaque server-side session ⇒ real logout + revocation (password change, role change, agency suspension, "sign out everywhere").
- **Refresh flow:** implicit — every authenticated request slides `idle_expires_at` forward (`last_seen_at = now()`), capped by `absolute_expires_at`. No separate refresh endpoint; on idle/absolute expiry the server returns `401 not_authenticated` and the frontend redirects. `⚑A4` Sliding sessions instead of access/refresh rotation because the cookie is HttpOnly and same-origin, so rotation buys little.

### 1.3 CSRF stance

Cookie sessions ⇒ CSRF is mandatory. **Double-submit token** + **`Sec-Fetch-Site`** check.

1. Frontend calls `GET /api/v1/csrf` once at boot (or Laravel's `/sanctum/csrf-cookie`). Server sets `XSRF-TOKEN`.
2. `js/api.js` reads `XSRF-TOKEN` from `document.cookie` and echoes it in **`X-XSRF-TOKEN`** on every non-GET request; always `credentials:"include"`.
3. Server rejects any state-changing request where header ≠ cookie **or** `Sec-Fetch-Site` ∉ {`same-origin`, `same-site`, `none`} → `403 csrf_mismatch`.

See [Architecture §5.1](02-architecture.md) for the `js/api.js` source.

### 1.4 CORS policy

`⚑A1` Same-origin ⇒ **CORS effectively off**. If ever split:

```
Access-Control-Allow-Origin: https://www.vfi-edu.example        # exact, never *
Access-Control-Allow-Credentials: true
Access-Control-Allow-Methods: GET, POST, PUT, DELETE
Access-Control-Allow-Headers: Content-Type, X-XSRF-TOKEN, X-Request-Id, Idempotency-Key
Vary: Origin
Access-Control-Max-Age: 600
```
Only the apex/www origin is allow-listed. Wildcard origin **must never** combine with `Allow-Credentials: true`.

### 1.5 Error envelope (single shape, every error)

```json
{
  "error": {
    "code": "validation_failed",
    "message": "One or more fields are invalid.",
    "request_id": "01J8ZK4T9Q2X",
    "fields": {
      "email": ["The email field must be a valid address."],
      "password": ["The password must be at least 8 characters."]
    },
    "retry_after": null
  }
}
```

| Field | Notes |
|---|---|
| `error.code` | Stable machine slug (snake_case). The client switches on this, never on `message`. |
| `error.message` | Human, English, safe to display. Never leaks internals. |
| `error.request_id` | Echoes `X-Request-Id` (§1.6). Always present. |
| `error.fields` | Present only for `validation_failed`; map of field → messages. |
| `error.retry_after` | Integer seconds; present on `429`/`503`. Mirrors the `Retry-After` header. |

**Canonical error codes**

| HTTP | `code` | When |
|---|---|---|
| 400 | `bad_request` | Malformed JSON / bad param types |
| 401 | `not_authenticated` | No/expired session |
| 401 | `invalid_credentials` | Sign-in failure (generic — never distinguishes unknown-user vs wrong-password) |
| 403 | `forbidden` | Authenticated but role/policy denies |
| 403 | `csrf_mismatch` | CSRF token / `Sec-Fetch-Site` failure |
| 403 | `tenant_scope_violation` | Partner touched another agency's row (should be unreachable; logged as incident) |
| 403 | `email_unverified` | `must_verify` gate on upload/submission |
| 403 | `agency_not_active` | Partner sign-in while pending/rejected/suspended |
| 403 | `field_frozen` | Legal name/DOB edit after an application is submitted |
| 404 | `not_found` | Resource absent **or** hidden by tenancy (§1.10) |
| 409 | `conflict` | Optimistic-concurrency / idempotency replay mismatch / duplicate |
| 409 | `stale_write` | `If-Match` version mismatch on a whole-list/array replace |
| 409 | `scan_pending` | Document requested before AV scan clean |
| 410 | `gone` | Page toggled off (enforced) / consumed reset token / destroyed OTP code |
| 413 | `payload_too_large` | Upload exceeds cap |
| 415 | `unsupported_media_type` | Upload MIME not in allow-list |
| 422 | `validation_failed` | Field validation |
| 422 | `file_rejected` | AV scan infected |
| 423 | `account_locked` | Lockout window active |
| 429 | `rate_limited` | Throttle tripped (`retry_after` set) |
| 503 | `service_unavailable` | Dependency (mail/AV/DB) down |

### 1.6 Request correlation

- Client **may** send `X-Request-Id` (ULID/UUID). If absent, server generates one.
- Server **always** returns `X-Request-Id` and stamps it into structured logs, `auth_events`, `content_audit_log`, `document_access_log`.

### 1.7 Pagination (cursor-based, default)

All list endpoints use **keyset/cursor** pagination — stable under inserts, critical for the wallet ledger and the unshift-to-front collections.

Request: `?limit=25&cursor=<opaque>` — `limit` default 25, max 100.

```json
{
  "data": [ ],
  "page": {
    "next_cursor": "eyJpZCI6",
    "prev_cursor": null,
    "limit": 25,
    "total_estimate": 1280
  }
}
```
`⚑A5` `total_estimate` is best-effort and omitted on program search and the wallet ledger (COUNT too expensive).

### 1.8 Filtering & sorting

- Filters are explicit named query params (never a generic `filter[…]` DSL). Documented per endpoint.
- Sorting: `?sort=field` asc, `?sort=-field` desc, multiple `?sort=-created_at,title`. Allowed sort fields enumerated per endpoint; anything else → `422`.
- Date-range filters use `*_from` / `*_to` (inclusive, `YYYY-MM-DD`).

### 1.9 Idempotency

- **All non-GET money/document/upload writes** and OTP/email sends accept an **`Idempotency-Key`** header (client UUID, stored 24 h in Redis keyed by `route+user+key`).
- Replay same key + same body ⇒ original stored response (same status). Same key + **different** body ⇒ `409 conflict` (`code: idempotency_key_reuse`).
- Required (server rejects the write without it): **`Idem: required`**. Optional: **`Idem: honored`**.

### 1.10 Tenancy (partner scope)

- The acting `partner_agency_id` comes **only** from the session. No endpoint reads an agency id from path/query/body.
- Enforced twice: Eloquent `BelongsToAgency` global scope; Postgres RLS (`SET LOCAL app.agency_id`). A missed predicate returns **zero rows / `404`**, never another agency's data.
- CI fails the build if any partner-scoped table is queried without a tenant predicate.

### 1.11 Content-layer special semantics (load-bearing — do not "fix")

- **Empty means fall-through.** `""` and `[]` mean "keep the page's built-in HTML." The API **round-trips them faithfully** and **never** substitutes defaults/nulls. `ConvertEmptyStringsToNull` + `TrimStrings` are **disabled on `/api/v1/content/*` and `/api/v1/admin/content/*`**.
- **Order is the array.** New collection items **unshift to front** (`position` below the current minimum). Explicit `position INTEGER` on all 10 collections + every override list.
- **Blog ids are public URLs** — preserved verbatim; never regenerated.
- Dates for events/blogs/DOB/intakes are `date`, not `timestamptz` (a `2026-09-02` event must not render as `01 Sep` for a Dhaka UTC+6 viewer).

### 1.12 Rate-limit classes

Every endpoint is tagged. Limits enforced server-side in Redis (per the key shown), independent of the cosmetic client cooldowns.

| Class | Limit | Key |
|---|---|---|
| `RL-PUBLIC-READ` | 120 / min | IP |
| `RL-AUTHED-READ` | 600 / min | session |
| `RL-SIGNIN` | 5 / 15 min then progressive lock | email **and** IP (both) |
| `RL-REGISTER` | 5 / hr | IP + email |
| `RL-OTP-SEND` | 3 / hr, min 30 s between | email (+ IP secondary) |
| `RL-OTP-VERIFY` | 5 / code then code destroyed | flow_id |
| `RL-RESET-REQ` | 3 / hr | email + IP |
| `RL-CONTACT` | 5 / hr + Turnstile | IP |
| `RL-SEARCH` | 30 / min | session |
| `RL-MONEY` | 10 / min | agency |
| `RL-UPLOAD` | 20 / 10 min | session |
| `RL-ADMIN-WRITE` | 60 / min | admin user |
| `RL-EXPORT` | 2 / hr | admin user |

`429` responses always carry `Retry-After` and `error.retry_after`.

---

## 2. Actors & roles

| Role | Cookie scope | Notes |
|---|---|---|
| `anonymous` | — | public reads + unauthenticated writes (register, forgot, contact, QR self-reg) |
| `student` | student | own profile/documents/tracking only |
| `partner_owner` / `partner_counsellor` / `partner_finance_viewer` | partner | tenant-scoped; owner+finance gate wallet writes |
| `content_editor` | admin | collections, page text, media |
| `staff_partner_ops` | admin | partner application review, agency suspend, student read-any |
| `staff_finance` | admin | wallet refund/adjust |
| `superadmin` | admin | backup import/export, reset, page toggle, role grant, admin invites |

MFA (TOTP) is **mandatory** for all admin roles; step-up re-auth for wallet writes, role changes, backup export.

---

## 3. Module: Auth

### 3.1 REAL REQUEST marker → endpoint map (the exact replacements)

| # | File:line | Stub call | **Real endpoint** | Payload fix |
|---|---|---|---|---|
| 1 | `auth.js:738` | `POST /api/{kind}` (`login`\|`register`) | `POST /api/v1/auth/student/login` **or** `/register` | Stub body drops `name` + `agree`; real register **must** include them (§3.2) |
| 2 | `auth.js:859` | `POST /api/password/reset` | `POST /api/v1/auth/student/password/forgot` | Enumeration-safe, always `202` |
| 3 | `auth.js:965` | `POST /api/verify/resend` | `POST /api/v1/auth/otp/resend` | Body `{email}` → `{flow_id}` |
| 4 | `auth.js:1115` | `POST /api/verify` | `POST /api/v1/auth/otp/verify` | Body `{email,code}` → `{flow_id,code}` |
| 5 | `partner-auth.js:761` | `POST /api/partner/{kind}` (`signin`\|`register`) | `POST /api/v1/auth/partner/login` **or** `/register` | Stub `collect(form)` does not exist; assemble from the 3 `[data-pa-step]` subtrees |
| 6 | `partner-auth.js:980` | `POST /api/partner/password/forgot` | `POST /api/v1/auth/partner/password/forgot` | Enumeration-safe, `202` |
| 7 | `partner-auth.js:1016` | (same as #6, resend) | `POST /api/v1/auth/partner/password/forgot` | Same endpoint; server enforces 30 s + 3/hr |
| 8 | `partner-auth.js:1243` | `POST /api/partner/email/verify` | `POST /api/v1/auth/otp/verify` | Unified with #4; `{flow_id,code}` |
| 9 | `partner-auth.js:1318` | `POST /api/partner/email/code` (resend) | `POST /api/v1/auth/otp/resend` | Unified with #3; `{flow_id}` |
| 10 | `partner-auth.js:1364` | `POST /api/partner/email/code` (**change address**) | `POST /api/v1/auth/otp/change-email` | **Security-critical rewrite** — §3.6. Bound to `flow_id`, not a client `?email=` |
| 11 | `portal.js:430` | `POST /api/partner/*` (shared modal) | `POST /api/v1/partner/students` **or** `/api/v1/partner/enquiries` | Split the shared handler per form (§5.2, §7.2) |

> **Design change:** student and partner OTP verify/resend collapse into **one** unified `/auth/otp/*` surface keyed by an opaque `flow_id`. This kills the `?email=` PII-in-URL leak and the account-takeover vector in one move.

### 3.2 Student sign-in / register

#### `POST /api/v1/auth/student/register` · anonymous · `RL-REGISTER` · Idem: honored
```json
{ "name": "Ayesha Rahman", "email": "a@x.com", "phone_cc": "+880",
  "phone": "1712345678", "password": "corr horse", "agree": true,
  "terms_version": "2026-05" }
```
```json
// 202 Accepted — response identical whether or not the email exists (enumeration-safe)
{ "flow_id": "otp_01J8Z", "purpose": "signup_student",
  "email_masked": "a•••@x.com", "expires_in": 600, "resend_in": 30 }
```
- Creates `users` row `status=pending_verification`, hashes password (argon2id), records `terms_acceptances`, issues OTP flow. **No session yet.**
- `422` on policy violation (password < 8, missing `agree`, bad email). Password max ≥ 64, no composition rules, breach-list check.

#### `POST /api/v1/auth/student/login` · anonymous · `RL-SIGNIN`
```json
{ "email": "a@x.com", "password": "…", "remember": true }
```
```json
// 200 — session cookie set
{ "user": { "id": "usr_…", "role": "student", "email_verified": false,
            "display_name": "Ayesha Rahman", "student_ref": "VFI-2026-04871" },
  "must_verify": true }
```
- Sets `vfi_session_student`. `⚑A6` Unverified students **may** sign in but carry `must_verify:true`; document upload + application submission are blocked until verified (`403 email_unverified`).
- Failure → `401 invalid_credentials` (generic; dummy-hash on unknown email for constant time). Lockout after ~10 consecutive failures → `423 account_locked` with `retry_after`.

### 3.3 Partner sign-in / register

#### `POST /api/v1/auth/partner/register` · anonymous · `RL-REGISTER`
Assemble from all three wizard steps (server re-validates every field; steps 1–2 were client-only):
```json
{ "agency_name": "…", "country": "BD", "city": "Dhaka",
  "contact_person": "Hakim", "work_email": "h@agency.com",
  "phone_cc": "+880", "phone": "1712345678",
  "password": "…", "agree": true, "terms_version": "2026-05",
  "ref_code": "abc123" }
```
```json
// 202
{ "flow_id": "otp_…", "purpose": "signup_partner", "email_masked": "h•••@agency.com",
  "expires_in": 600, "resend_in": 30 }
```
- Creates `users` (`pending_verification`) + `partner_applications` (`review_status=pending`). **Does not create a live `partner_agencies` tenant** — that happens on staff approval (§5.4). `agree` records **both** terms acceptance and authority-to-bind attestation. `ref_code` (from `?ref=`) attributes nothing until approval.

#### `POST /api/v1/auth/partner/login` · anonymous · `RL-SIGNIN`
```json
{ "email": "h@agency.com", "password": "…", "remember": true }
```
```json
// 200 — vfi_session_partner set, tenant bound into the session
{ "user": { "id": "usr_…", "seat_role": "owner", "display_name": "Hakim" },
  "agency": { "id": "agc_…", "name": "…", "status": "approved", "tier": "STARTER" } }
```
- Refuses sign-in when agency `status ∈ {pending_review, rejected, suspended}` → `403 agency_not_active` (message distinguishes only "under review" vs "contact VFI"). Binds `active_partner_agency_id` into the session row.

### 3.4 Password reset (both actors)

| Endpoint | Actor | Class |
|---|---|---|
| `POST /api/v1/auth/student/password/forgot` | anonymous | `RL-RESET-REQ` |
| `POST /api/v1/auth/partner/password/forgot` | anonymous | `RL-RESET-REQ` |
| `POST /api/v1/auth/password/reset` | anonymous (token bearer) | `RL-RESET-REQ` |

`forgot` → always `202`, body identical whether or not the address exists:
```json
{ "message": "If that email is registered, a reset link is on its way." }
```
`reset` **consumes** the emailed token (the landing pages — `student-reset.html` / `vfi-partner-reset.html` — **do not exist yet and must be built**):
```json
{ "token": "<32-byte hex from email link>", "password": "new…", "password_confirm": "new…" }
```
- `200`: verifies token hash + expiry (30–60 min) + unused; enforces password policy server-side; **revokes ALL sessions** for the user; invalidates every other outstanding reset token.
- `410 gone` if consumed/expired; `422` on policy/mismatch.

### 3.5 Unified OTP (email verification)

| Endpoint | Purpose | Class |
|---|---|---|
| `POST /api/v1/auth/otp/verify` | check 6-digit code | `RL-OTP-VERIFY` |
| `POST /api/v1/auth/otp/resend` | new code, invalidate previous | `RL-OTP-SEND` |
| `POST /api/v1/auth/otp/change-email` | re-point a pending flow (§3.6) | `RL-OTP-SEND` |

`verify`:
```json
{ "flow_id": "otp_…", "code": "418209" }
```
```json
// 200 — on signup flows marks email_verified_at and (policy) may issue the session
{ "verified": true, "user": { "id": "usr_…", "role": "student" }, "session_started": true }
```
- 6 digits, CSPRNG, **hashed at rest**, constant-time compare, 10-min TTL, **max 5 attempts** then code destroyed → subsequent attempts `410 gone`. Wrong code → `422 otp_invalid` with `attempts_remaining`.
- `resend` → `202 { flow_id, resend_in: 30 }`; invalidates the prior code; hard cap 3/hr/email.

### 3.6 OTP change-email (the account-takeover fix)

`POST /api/v1/auth/otp/change-email` · anonymous (flow bearer) · `RL-OTP-SEND`
```json
{ "flow_id": "otp_…", "new_email": "real@owner.com" }
```
- Requires possession of the **`flow_id`** (server-side pending-registration record), **not** a `?email=` query param. Changing the address: (a) validates the flow is still open, (b) restarts the flow, (c) invalidates every prior code, (d) capped at **2 address changes per registration**. Returns `202 { flow_id, email_masked, resend_in }`.
- Replaces `partner-auth.js:1364`, which as written lets anyone re-point a stranger's verification to an attacker inbox.

### 3.7 Session lifecycle

| Endpoint | Actor | Response |
|---|---|---|
| `GET /api/v1/csrf` | any | `204`, sets `XSRF-TOKEN` |
| `GET /api/v1/auth/me` | any authed | `200` identity or `401` |
| `POST /api/v1/auth/logout` | any authed | `204`, revokes current session, clears cookie |
| `POST /api/v1/auth/logout-all` | any authed | `204`, revokes all sessions for the user |

`GET /api/v1/auth/me` — the boot call every guarded page makes; on `401` the page redirects and renders nothing:
```json
{ "authenticated": true, "role": "partner_owner",
  "user": { "id": "usr_…", "display_name": "Hakim", "email_verified": true },
  "agency": { "id": "agc_…", "name": "…", "tier": "STARTER" },
  "wallet_balance_minor": 0, "unread_notifications": 0 }
```

### 3.8 Admin auth

| Endpoint | Actor | Notes |
|---|---|---|
| `POST /api/v1/admin/auth/login` | anonymous | step 1: email+password → `200 {mfa_required:true, mfa_token}` |
| `POST /api/v1/admin/auth/mfa` | anonymous (mfa_token) | step 2: TOTP → sets `vfi_session_admin` |
| `POST /api/v1/admin/auth/logout` | admin | `204` |
| `POST /api/v1/admin/auth/step-up` | admin | re-assert TOTP for wallet/role/export ops → short-lived `step_up` claim |

No admin self-signup. Accounts come via `admin_invites` (superadmin-issued, expiring, single-use). Idle 15 min.

---

## 4. Module: Students (self-service portal)

All endpoints are **implicit-self** — the student is resolved from the session, never an id in the path. GETs are `RL-AUTHED-READ` unless noted. Requires `vfi_session_student`.

| Method | Path | Purpose | Notes |
|---|---|---|---|
| GET | `/api/v1/me/profile` | full profile bundle | personal + address + qualifications[] + test_scores[] + preferences + both doc packs + completeness |
| PUT | `/api/v1/me/profile/personal` | update personal card | some fields frozen post-submission (§4.2) |
| PUT | `/api/v1/me/profile/address` | update correspondence address | |
| PUT | `/api/v1/me/profile/qualifications` | **replace** whole array | `If-Match` concurrency |
| PUT | `/api/v1/me/profile/test-scores` | **replace** whole array | `If-Match` |
| PUT | `/api/v1/me/profile/preferences` | update prefs + destinations[] | |
| GET | `/api/v1/me/completeness` | derived meter | 26-item score, visa pack excluded (§4.3) |
| GET | `/api/v1/me/documents` | checklist (both packs) | server-driven doc types |
| POST | `/api/v1/me/documents/{slot_key}` | upload one slot | multipart; §7.1 |
| DELETE | `/api/v1/me/documents/{slot_key}` | clear slot → `missing` | blocked once `verified` → `409` |
| GET | `/api/v1/me/documents/{slot_key}/url` | short-lived signed download | logged |
| GET | `/api/v1/me/tracking` | journey + applications + timeline + todos | one GET, read-only |

### 4.1 Profile bundle response (abridged)
```json
{
  "student": { "student_ref": "VFI-2026-04871", "display_name": "Ayesha Rahman" },
  "personal": { "first_name":"Ayesha","middle_name":"","last_name":"Rahman",
                "dob":"2004-03-11","nationality":"Bangladeshi",
                "phone_cc":"+880","phone":"1712345678","email":"a@x.com",
                "editable": true },
  "address": { "line1":"…","line2":"","city":"Dhaka","district":"Dhaka",
               "postcode":"1212","country":"Bangladesh" },
  "qualifications": [ { "id":"qual_…","position":0,"qualification":"HSC",
                        "institution":"…","year_completed":"2022","grade":"GPA 5.00 of 5.00" } ],
  "test_scores": [ { "id":"ts_…","position":0,"test_name":"IELTS Academic",
                     "overall_score":"7.5","date_taken":"2026-01-20" } ],
  "preferences": { "intake":"September 2026","budget_band":"USD 15,000–25,000",
                   "field_of_study":"Computing & Data",
                   "destinations":["GB","CA","AU"] },
  "documents": { "application": [ ], "visa": [ ] },
  "completeness": { "pct": 73, "done": 19, "total": 26 },
  "version": "2026-08-09T10:00:00Z"
}
```

### 4.2 Field-freeze rule
Once any application is `submitted`, `first_name`/`last_name`/`dob` become non-self-editable → `PUT …/personal` with those changed returns `403 field_frozen`; they become staff-approved change requests. `⚑A7` The change-request queue is a staff surface not in the current UI; modelled but deferred.

### 4.3 Completeness (reproduce exactly)
26 scored items = 6 personal (first,last,dob,nationality,phone,email) + 5 address (line2 excluded) + 3 fixed academic slots + 2 fixed test slots + 4 preferences (≥1 country, intake, budget, field) + 6 **application** documents (`uploaded` OR `verified`). Visa pack **not** scored. Thresholds: ≥85 `good`, <55 `warn`. **Computed server-side** so counsellor and student views agree.

### 4.4 Concurrency
`qualifications` and `test-scores` are whole-array replace. Client sends `If-Match: "<version>"`. Mismatch → `409 stale_write` with the current server version so the client can re-fetch and merge.

---

## 5. Module: Partners / Agencies

Requires `vfi_session_partner`. Tenant-scoped (§1.10). `data-pp-*` console pages boot via `GET /api/v1/auth/me`.

### 5.1 Console content (admin-driven, partner-read)
`GET /api/v1/partner/console` · partner · `RL-AUTHED-READ`
Returns the `[data-ppr]` bundle **behind the partner session** (today world-readable in localStorage): managers, updates, quicklinks, docs, emails, notifs summary, tier text.
```json
{ "greeting": { "name": "Hakim", "initial": "H" },
  "tier": { "name":"STARTER","rank":1,"progress_pct":16,"unique_visa_received_12mo":0,
            "students_to_next":6,"next_tier":"Growth",
            "benefits":["100 assistant messages per month","Up to 2 counsellors",
                        "20 student autofills per year","10 AI mock interviews per year"] },
  "quotas": { "assistant_messages": {"used":0,"limit":100,"period":"month"},
              "ai_mock_interviews": {"used":0,"limit":10,"period":"year"} },
  "managers": [ ], "updates": [ ], "quicklinks": [ ] }
```
> **Fix carried:** `partnerName` (the single global admin string) is **dropped** as an identity source; greeting comes from the authenticated user.

### 5.2 Students (tenant-scoped)
| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/v1/partner/students` | partner | list; filters below |
| POST | `/api/v1/partner/students` | partner | **replaces `portal.js:430` register modal**; Idem: honored |
| GET | `/api/v1/partner/students/{id}` | partner | detail |
| PUT | `/api/v1/partner/students/{id}` | partner | edit |
| POST | `/api/v1/partner/students/{id}/archive` | partner | soft |
| POST | `/api/v1/partner/students/{id}/unarchive` | partner | |

**List filters:** `created_from`, `created_to`, `country`, `intake_month`, `intake_year`, `status`, `q`, `archived=true`. **Sort:** `created_at`, `last_name`. Cursor-paginated.

`POST /api/v1/partner/students`:
```json
{ "first_name":"…","middle_name":"","last_name":"…",
  "phone_cc":"+880","phone":"…","email":"…" }
```
- `agency_id` from session only. **Duplicate policy:** email already owned by **another** agency → `409 student_owned_elsewhere` (routes to a claim/transfer flow, never silent re-parent). Same-agency duplicate → `409 duplicate_student`.

### 5.3 Referral / QR
| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/v1/partner/referral` | partner | opaque `slug`, share URL, **real** QR asset URL |
| POST | `/api/v1/partner/referral/rotate` | partner_owner | new slug, revokes old |
| POST | `/api/v1/public/students/register-via-qr` | anonymous | student self-reg attributed to a slug |

`register-via-qr` is an **unauthenticated tenancy write** → Turnstile + `RL-REGISTER` + slug validity/revocation check + email verification before attribution counts. Body `{ slug, name, email, phone_cc, phone, password, agree }`; returns a signup OTP flow (§3.5).

### 5.4 Partner application review (staff)
| Method | Path | Role |
|---|---|---|
| GET | `/api/v1/admin/partner-applications` | staff_partner_ops |
| GET | `/api/v1/admin/partner-applications/{id}` | staff_partner_ops |
| POST | `/api/v1/admin/partner-applications/{id}/approve` | staff_partner_ops |
| POST | `/api/v1/admin/partner-applications/{id}/reject` | staff_partner_ops |
| POST | `/api/v1/admin/partner-applications/{id}/request-info` | staff_partner_ops |
| POST | `/api/v1/admin/agencies/{id}/suspend` | staff_partner_ops |

`approve` creates the live `partner_agencies` row + grants first `partner_owner` role + emails the applicant. `suspend` blocks sign-in, **revokes every member session**, freezes wallet writes.

---

## 6. Module: Applications

`⚑A8` Greenfield (no UI today). Statuses (native PHP enum): `submitted | review | offer | conditional | rejected | enrolled` (student view) mapped from the KPI vocabulary `all | offers | payments | visa_received | visa_rejected | non_enrolment | deferrals | pending_from_partner`.

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/v1/partner/applications` | partner | tenant list; filters `status`,`intake_month`,`intake_year`,`country`,`deadline_bucket` |
| POST | `/api/v1/partner/applications` | partner | create for a student+program; debits app fee atomically (§9); Idem: **required** |
| GET | `/api/v1/partner/applications/{id}` | partner | detail + status timeline |
| GET | `/api/v1/partner/dashboard/kpis` | partner | the 8 counters, filtered |
| GET | `/api/v1/partner/dashboard/deadlines` | partner | buckets `d0..d3` |
| POST | `/api/v1/admin/applications/{id}/status` | staff | append `application_status_events`, emit notification, move KPIs; Idem: honored |

`GET /api/v1/partner/dashboard/kpis?date_from=&date_to=&intake_month=&intake_year=&country=`:
```json
{ "all_applications":0,"offers":0,"payments":0,"visa_received":0,
  "visa_rejected":0,"non_enrolment":0,"deferrals":0,"pending_from_partner":0,
  "computed_at":"2026-08-09T10:00:00Z" }
```
KPIs are computed tenant-scoped server-side (`GROUP BY status` over a covering index / nightly `dashboard_kpi_rollup`), never client-filtered.

---

## 7. Module: Documents & Enquiries

### 7.1 Upload pipeline (shared by student docs, partner enquiry docs, program-request docs)
`POST` multipart, one file per request. **v1 flow:** browser → API → magic-byte sniff → size cap → **ClamAV scan-gate (`documents` queue)** → private R2 with **server-generated UUID key** (never the client filename) → row `status: uploaded`, `av_scan_status: pending`. File is **not readable** until `av_scan_status: clean`.

| Rule | Value |
|---|---|
| Allow-list | `application/pdf`, `image/jpeg`, `image/png` (server-sniffed; the student input has no `accept` attr, so the client cannot be trusted) |
| Max size | 15 MB/file `⚑A9`; JPEG/PNG canvas-downscaled server-side (never PDFs) |
| Storage | Private bucket, UUID key, encrypted at rest |
| Download | Per-request presigned GET, **60–300 s, single-use**, every mint written to `document_access_log` |
| `Idem` | required (prevents duplicate blobs on retry over flaky Dhaka mobile) |
| Class | `RL-UPLOAD` |

Upload response:
```json
{ "slot_key":"passport","status":"uploaded","av_scan_status":"pending",
  "file":{ "id":"doc_…","original_filename":"passport.pdf","byte_size":842210 },
  "uploaded_at":"2026-08-09T10:00:00Z" }
```
Retrieving a document whose scan is still `pending` → `409 scan_pending`; `infected` → `422 file_rejected`. See the [Architecture sequence diagram §8](02-architecture.md) for the full upload-then-counsellor-view flow.

### 7.2 Partner enquiries (Request Program Options)
Splits the shared `portal.js:430` handler.
| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/v1/partner/enquiries` | partner | list |
| POST | `/api/v1/partner/enquiries` | partner | create request (metadata) |
| POST | `/api/v1/partner/enquiries/{id}/documents` | partner | attach academic docs (§7.1) |

`POST /api/v1/partner/enquiries`:
```json
{ "enquiry_type":"new",
  "student_id": null, "first_name":"…","middle_name":"","last_name":"…",
  "country_of_education":"BD","highest_education_level":"Bachelor's",
  "destination":"CA","preferred_study_area":"…","preferred_study_level":"PG",
  "program_label":"STEM","additional_info":"…" }
```
`enquiry_type`: `"new"` = not in VFI, `"existing"` = in VFI (`student_id` required and tenant-checked).

### 7.3 Verification (staff)
| Method | Path | Role |
|---|---|---|
| POST | `/api/v1/admin/students/{id}/documents/{slot}/verify` | staff |
| POST | `/api/v1/admin/students/{id}/documents/{slot}/reject` | staff (adds the missing `rejection_reason`) |

Every verify/reject writes `document_status_history` with actor + reason.

---

## 8. Module: Content / CMS

### 8.1 Public read — one bootstrap bundle per page (keeps `js/store.js` synchronous)
`GET /api/v1/content/bundle?page={filename}` · anonymous · `RL-PUBLIC-READ` · **CDN-cacheable, ETag**

One call returns everything the page's `render.js` needs, so the synchronous accessors (`VFI.list`, `VFI.settings`, `VFI.country`, …) read from the pre-populated cache. Injected as `<script>window.VFI_BOOTSTRAP={…}</script>` before `store.js`.
```json
{ "settings": { "brand":"…","phone":"…" },
  "page_visibility": { "study-in-uk.html": true },
  "media": { "hero":"img_…","collage1":"assets/img/x.jpg" },
  "collections": { "events":[], "blogs_index":[], "news":[], "photos":[] },
  "overrides": { "country": {}, "region": {}, "servicesPage":{}, "partnerPage":{} },
  "etag":"W/\"abc123\"" }
```
- `blogs_index` **omits** `body`. `Cache-Control: public, max-age=60, stale-while-revalidate=300`; purged on publish. Empty values emitted verbatim (never backfilled).

Granular reads (also available, for progressive fetches):

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/content/settings` | 14 global fields |
| GET | `/api/v1/content/collections/{kind}` | `kind ∈ events,blogs,news,photos,ppManagers,…`; ordered by `position` |
| GET | `/api/v1/content/blogs/{id}` | full body; **`404` distinguishable** (render.js paints a friendly dead-end) |
| GET | `/api/v1/content/countries/{slug}` | text + 4 override lists |
| GET | `/api/v1/content/regions/{slug}` | hero + bands |
| GET | `/api/v1/content/services` | hero + blocks |
| GET | `/api/v1/content/partner-page` | 14 fields + 5 lists |
| GET | `/api/v1/content/pages` | filename→bool visibility map |
| GET | `/media/{id}.jpg` | **static, immutable, 1-yr cache**, via CDN (not under `/api`) |

**Image resolution:** `GET /media/{id}.jpg` returns a real URL/redirect. The dual-mode contract survives: an id containing `/`, matching `^https?:`, or ending in an image extension resolves to **itself** (so bundled `assets/img/*.jpg` seed defaults keep working); a generated `img_*` id resolves to R2. `⚑A10` `getImage()` in `store.js` becomes a pure string→URL map — one-function change.

### 8.2 Page-off enforcement (not cosmetic)
When a page is toggled off, the **edge returns `410 gone`** for that HTML path (real hiding), and `GET /content/bundle?page=…` returns `{ "page_off": true }` so `render.js`/`auth.js` paint the notice. Toggling `login.html` / `vfi-partner-login.html` is privileged, audited; the two sign-in entries are "Always on" like their sub-flows. `⚑A11` Marketing on/off remains a menu+notice toggle unless the client opts into edge-410 hiding.

### 8.3 Admin CMS (content_editor) — under `/api/v1/admin/content/*`
Generic CRUD across the 10 collections + 5 override singletons. **`ConvertEmptyStringsToNull`/`TrimStrings` disabled here.** All `RL-ADMIN-WRITE`.

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/v1/admin/content/{kind}` | content_editor | admin list (incl. unpublished if added) |
| POST | `/api/v1/admin/content/{kind}` | content_editor | create; **unshift** — server assigns `position` to front + id |
| PATCH | `/api/v1/admin/content/{kind}/{id}` | content_editor | **merge** over stored object (fields outside schema survive) |
| DELETE | `/api/v1/admin/content/{kind}/{id}` | content_editor | **soft delete**; image deletion checks refcount first |
| POST | `/api/v1/admin/content/{kind}/reorder` | content_editor | explicit `[{id,position}]` — the missing reorder op |
| PUT | `/api/v1/admin/content/settings` | content_editor | replace-merge 14 fields (blank truly blanks) |
| PUT | `/api/v1/admin/content/countries/{slug}` | content_editor | text merge OR whole-list replace per key; `If-Match` |
| PUT | `/api/v1/admin/content/regions/{slug}` | content_editor | bands whole-list replace; `If-Match` |
| PUT | `/api/v1/admin/content/services` | content_editor | blocks whole-list replace; `If-Match` |
| PUT | `/api/v1/admin/content/partner-page` | content_editor | 5 lists whole-list replace; `If-Match` |
| PUT | `/api/v1/admin/content/partner-console` | content_editor | 7 text fields |
| POST | `/api/v1/admin/media` | content_editor | upload (canvas downscale, EXIF strip); Idem: honored; `RL-UPLOAD` |
| PUT | `/api/v1/admin/media/slots/{key}` | content_editor | point slot at image; auto-deletes prior (refcount-checked) |
| PUT | `/api/v1/admin/content/pages/{file}` | superadmin | toggle visibility; validates against a fixed catalogue; audited |
| GET | `/api/v1/admin/content/counts` | content_editor | cheap dashboard counts |

**URL-scheme allow-list** enforced server-side on `ppQuicklinks.url`, `ppDocs.url`, `services_blocks.ctaHref`: only `http/https/mailto/relative` → else `422 unsafe_url`. **Blog body stays plain-text** end-to-end (validated; no HTML).

**Whole-list replace concurrency:** `If-Match: "<version>"` required on the four whole-list PUTs; stale → `409 stale_write` (prevents the silent data-loss risk where a second editor clobbers the first).

### 8.4 Backup (superadmin, step-up + MFA)
| Method | Path | Role | Class |
|---|---|---|---|
| POST | `/api/v1/admin/backup/export` | superadmin (step-up) | `RL-EXPORT` — creates snapshot, returns signed expiring link |
| POST | `/api/v1/admin/backup/import` | superadmin (step-up) | schema-validated, size-capped, **auto pre-restore snapshot**, audited; Idem: required |
| POST | `/api/v1/admin/content/reset` | superadmin (step-up) | snapshot-first; `⚑A12` gate behind a build flag / remove in prod |

---

## 9. Module: Wallet (financial — highest write privilege)

Requires `vfi_session_partner`; **writes require `seat_role ∈ {owner, finance_viewer}` + step-up**. Money is `NUMERIC` minor units; ledger append-only with `balance_after_minor`.

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/v1/partner/wallet` | partner | balance + currency |
| GET | `/api/v1/partner/wallet/transactions` | partner | **cursor** ledger; filters `txn_ref`,`type`,`date_from/to`,`ack_no`,`student`,`amount` |
| POST | `/api/v1/partner/wallet/topups` | owner/finance (step-up) | create PSP intent; Idem: **required**; `RL-MONEY` |
| POST | `/api/v1/webhooks/psp/{provider}` | system (signed) | authoritative credit; dedupe on `provider_event_id` |
| POST | `/api/v1/webhooks/flywire` | system (signed) | tuition status callbacks |
| POST | `/api/v1/admin/wallet/{agency}/refund` | staff_finance (step-up) | reversal, reason-coded, audited |
| POST | `/api/v1/admin/wallet/{agency}/adjust` | staff_finance (step-up) | manual adjustment |

**Top-up correctness:**
- `POST /wallet/topups` returns a checkout handle; **the wallet is credited only by the verified webhook**, never the browser redirect.
- Webhook: verify signature → dedupe `provider_event_id` (store raw in `payment_provider_events`) → **inside a serialisable txn**: `SELECT … FOR UPDATE` wallet, insert `wallet_transactions` with `balance_after_minor`, update `wallets.balance_minor` with optimistic `version`. Return `200` even on replay (idempotent).

**Application-fee debit** happens atomically inside `POST /partner/applications` (§6): one transaction inserts the application + the `application_fee_charges` debit + the ledger row, or rolls back entirely.

**Currency `⚑A13`:** balance in BDT (৳) but fees charged in USD/GBP/…; store `fx_rate` per transaction. Recommend BDT wallet + stored FX at charge time; decide before the wallet DDL is frozen.

---

## 10. Module: Notifications

Requires session. `⚑A14` v1 uses short-poll; the "real-time" copy is honoured later via an SSE side-service (deferred per ADR).

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/api/v1/partner/notifications` | partner | cursor list, read/unread |
| GET | `/api/v1/partner/notifications/summary` | partner | unread count + latest 5 (populates the bell) |
| POST | `/api/v1/partner/notifications/{id}/read` | partner | Idem: honored |
| POST | `/api/v1/partner/notifications/read-all` | partner | |

> Fixes the two-sources-of-truth bug: the bell popover and the notifications page both read this one endpoint.

---

## 11. Module: Search (programs)

`GET /api/v1/partner/programs/search` · partner · `RL-SEARCH`

PostgreSQL flat `program_search` table (GIN `tsvector` + `smallint[]` facet flags + composite b-tree). **Keyset** pagination. No live per-facet counts in v1 (`⚑A15` — the current UI shows none; Typesense is the documented exit if demanded).

**Query params (all combinable):**
| Param | Type | Facet |
|---|---|---|
| `q` | string | free text over program + university name (tsvector; `pg_trgm` typeahead) |
| `country`,`province`,`city` | string | institution |
| `level` | csv enum (16) | program |
| `study_area`,`discipline_area`,`duration` | csv enum | program |
| `intake_month`,`intake_year` | enum | `program_intakes` |
| `nationality`,`student_state` | enum | eligibility |
| `req[]` | csv flags (16, incl. negative waiver flags) | `smallint[] @>` |
| `label[]` | csv (14 chips) | labels |
| `esl`,`stem`,`coop`,`scholarship`,`fee_waiver`,`moi`,`affordable`,`no_deposit`,`no_interview` | bool | flags |
| `sort` | `-deadline\|tuition\|-offer_tat` | |
| `limit`,`cursor` | pagination | |

Response `data[]`: `{ program_id, title, institution:{name,country,city,logo_url}, level, tuition_fee_minor, currency, application_fee_minor, next_intake:{month,year,deadline_at}, flags:[…] }`.

Supporting:
| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/partner/programs/facets` | served vocabularies (kills the 5 divergent hardcoded copies) |
| GET | `/api/v1/partner/programs/{id}` | detail (fees, requirements, intakes, institution) |

---

## 12. Module: Public (unauthenticated writes)

| Method | Path | Notes |
|---|---|---|
| POST | `/api/v1/public/contact` | **persists the lead** (today silently discarded); Turnstile + `RL-CONTACT` |
| POST | `/api/v1/public/newsletter` | double opt-in; `RL-CONTACT` |
| POST | `/api/v1/public/students/register-via-qr` | §5.3 |

`POST /api/v1/public/contact`:
```json
{ "fname":"…","phone":"…","email":"…","dest":"CA","msg":"…",
  "source_page":"contact.html","turnstile_token":"…" }
```
→ `202 { "message":"Thanks — we'll be in touch." }`. Stores `contact_enquiries` (status `new`), notifies staff. Admin inbox: `GET /api/v1/admin/enquiries` (staff), `POST …/{id}/status`.

---

## 13. Minimal DDL (the load-bearing shapes)

Full schema in [Data model](03-data-model.md); these are the shapes the contract depends on.

```sql
-- Ordering + soft delete on every collection (events shown; identical for the 10)
CREATE TABLE events (
  id           text PRIMARY KEY,              -- uid('e') preserved verbatim
  position     integer NOT NULL,              -- unshift: new rows get MIN(position)-1
  title        text NOT NULL,
  date         date,                          -- NOT timestamptz (UTC+6 render bug)
  time         text, type text, city text, "desc" text, color text,
  img_id       text REFERENCES images(id),
  published    boolean NOT NULL DEFAULT true,
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now(),
  deleted_at   timestamptz
);
CREATE INDEX ON events (position) WHERE deleted_at IS NULL;

-- Override singletons: jsonb, empty-string preserving
CREATE TABLE content_overrides (
  key    text PRIMARY KEY,   -- 'settings','country:uk','region:asia','servicesPage',…
  data   jsonb NOT NULL,     -- "" and [] stored literally; app layer never coalesces
  version timestamptz NOT NULL DEFAULT now()   -- If-Match / optimistic concurrency
);

-- Money: append-only ledger
CREATE TABLE wallet_transactions (
  id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  wallet_id          uuid NOT NULL REFERENCES wallets(id),
  agency_id          uuid NOT NULL,           -- denormalised for RLS
  txn_ref            text UNIQUE NOT NULL,
  type               text NOT NULL,           -- topup|application_fee|refund|adjustment
  direction          text NOT NULL,           -- credit|debit
  amount_minor       numeric(14,0) NOT NULL,
  currency           text NOT NULL,
  balance_after_minor numeric(14,0) NOT NULL,
  idempotency_key    text,
  provider_ref       text,
  created_at         timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX ON wallet_transactions (wallet_id, idempotency_key)
  WHERE idempotency_key IS NOT NULL;
ALTER TABLE wallet_transactions ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant ON wallet_transactions
  USING (agency_id = current_setting('app.agency_id')::uuid);
-- No UPDATE/DELETE grant to the app role -> append-only.

-- Documents: private storage, scan gate, access log
CREATE TABLE student_documents (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  student_id    uuid NOT NULL,
  pack          text NOT NULL,      -- application|visa
  slot_key      text NOT NULL,
  status        text NOT NULL DEFAULT 'missing',  -- missing|uploaded|verified|rejected
  storage_key   uuid,              -- server-generated; NEVER the client filename
  original_filename text, content_type text, byte_size bigint, sha256 text,
  av_scan_status text NOT NULL DEFAULT 'pending', -- pending|clean|infected
  rejection_reason text,
  uploaded_at timestamptz, verified_by uuid, verified_at timestamptz,
  UNIQUE (student_id, pack, slot_key)
);
```

---

## 14. OpenAPI skeleton

```yaml
openapi: 3.1.0
info:
  title: VFI Overseas Education API
  version: "1.0.0"
servers:
  - url: /api/v1
components:
  securitySchemes:
    studentSession: { type: apiKey, in: cookie, name: vfi_session_student }
    partnerSession: { type: apiKey, in: cookie, name: vfi_session_partner }
    adminSession:   { type: apiKey, in: cookie, name: vfi_session_admin }
  parameters:
    Cursor: { name: cursor, in: query, schema: { type: string } }
    Limit:  { name: limit,  in: query, schema: { type: integer, default: 25, maximum: 100 } }
    Sort:   { name: sort,   in: query, schema: { type: string } }
    IfMatch: { name: If-Match, in: header, schema: { type: string } }
    IdempotencyKey: { name: Idempotency-Key, in: header, schema: { type: string, format: uuid } }
  headers:
    RequestId: { schema: { type: string }, description: Echoed X-Request-Id }
    RetryAfter: { schema: { type: integer } }
  schemas:
    Error:
      type: object
      required: [error]
      properties:
        error:
          type: object
          required: [code, message, request_id]
          properties:
            code: { type: string }
            message: { type: string }
            request_id: { type: string }
            fields: { type: object, additionalProperties: { type: array, items: { type: string } } }
            retry_after: { type: [integer, "null"] }
    Page:
      type: object
      properties:
        next_cursor: { type: [string, "null"] }
        prev_cursor: { type: [string, "null"] }
        limit: { type: integer }
        total_estimate: { type: integer }
    OtpFlow:
      type: object
      properties:
        flow_id: { type: string }
        purpose: { type: string, enum: [signup_student, signup_partner, email_change] }
        email_masked: { type: string }
        expires_in: { type: integer }
        resend_in: { type: integer }
  responses:
    Error4xx:
      description: Error envelope
      headers: { X-Request-Id: { $ref: '#/components/headers/RequestId' } }
      content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } }
    RateLimited:
      description: Too many requests
      headers:
        Retry-After: { $ref: '#/components/headers/RetryAfter' }
        X-Request-Id: { $ref: '#/components/headers/RequestId' }
      content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } }
paths:
  /auth/student/register:
    post:
      operationId: studentRegister
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [name, email, password, agree]
              properties:
                name: { type: string }
                email: { type: string, format: email }
                phone_cc: { type: string }
                phone: { type: string }
                password: { type: string, minLength: 8 }
                agree: { type: boolean }
                terms_version: { type: string }
      responses:
        "202": { description: OTP flow started,
                 content: { application/json: { schema: { $ref: '#/components/schemas/OtpFlow' } } } }
        "422": { $ref: '#/components/responses/Error4xx' }
        "429": { $ref: '#/components/responses/RateLimited' }
  /auth/student/login:
    post:
      operationId: studentLogin
      responses:
        "200": { description: Session established }
        "401": { $ref: '#/components/responses/Error4xx' }
        "423": { $ref: '#/components/responses/Error4xx' }
        "429": { $ref: '#/components/responses/RateLimited' }
  /auth/otp/verify:
    post:
      operationId: otpVerify
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [flow_id, code]
              properties: { flow_id: { type: string }, code: { type: string, pattern: '^[0-9]{6}$' } }
      responses:
        "200": { description: Verified }
        "410": { $ref: '#/components/responses/Error4xx' }   # code destroyed
        "422": { $ref: '#/components/responses/Error4xx' }
  /partner/wallet/topups:
    post:
      operationId: walletTopup
      security: [ { partnerSession: [] } ]
      parameters: [ { $ref: '#/components/parameters/IdempotencyKey' } ]  # required
      responses:
        "201": { description: PSP intent created }
        "403": { $ref: '#/components/responses/Error4xx' }
        "409": { $ref: '#/components/responses/Error4xx' }
        "429": { $ref: '#/components/responses/RateLimited' }
  /partner/programs/search:
    get:
      operationId: programSearch
      security: [ { partnerSession: [] } ]
      parameters:
        - { $ref: '#/components/parameters/Cursor' }
        - { $ref: '#/components/parameters/Limit' }
        - { $ref: '#/components/parameters/Sort' }
        - { name: q, in: query, schema: { type: string } }
        - { name: req, in: query, style: form, explode: false,
            schema: { type: array, items: { type: string } } }
      responses:
        "200": { description: Results page }
        "429": { $ref: '#/components/responses/RateLimited' }
  /content/bundle:
    get:
      operationId: contentBundle
      parameters:
        - { name: page, in: query, required: true, schema: { type: string } }
      responses:
        "200":
          description: Per-page content bootstrap (ETag, CDN-cached)
          headers: { ETag: { schema: { type: string } } }
        "304": { description: Not modified }
        "410": { description: Page toggled off }
```

---

## 15. Build-order note (mirrors the ADR milestone)

1. **Weeks 1–3:** admin auth + TOTP (`/admin/auth/*`) **before any admin content endpoint is exposed**; `POST /public/contact` persistence. Closes the two live emergencies.
2. Tenancy net (scope + RLS + CI guard) before any partner data endpoint.
3. Content bundle + `store.js` HTTP-client rewrite → 32 marketing pages migrate.
4. Student/partner auth (the 11 REAL REQUEST points) behind one `VFI_API_BASE`; remove per-page demo disclaimers as each flow goes live.
5. Documents (scan-gated) before the first real byte. Then applications → wallet → search.

See [Architecture §11](02-architecture.md) for the phase-by-phase build order and [Data model](03-data-model.md) for full DDL.
