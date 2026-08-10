# Phase 4 — Student Identity: Registration, Sign-in, OTP, Password Reset

Build spec for the phase that delivers real student authentication end-to-end. For engineers and AI agents implementing the backend. Read [memory.md](../../memory.md) and the [phase index](README.md) first. Sibling references: [Data model](../03-data-model.md) · [API contract](../04-api-contract.md) · [Security & compliance](../05-security-and-compliance.md).

---

## Goal

Replace the front-end demo (every submit is a `setTimeout` that always succeeds; any six digits pass OTP) with real student auth: register / sign-in, a hardened 6-digit email OTP bound to an opaque `flow_id`, and the full password-reset flow **including `student-reset.html`, which does not exist in the repo today** (both reset flows currently dead-end at "check your inbox").

## Duration

3–4 weeks.

## Prerequisites

| Needs | From | Why |
|---|---|---|
| `users` (argon2id), `user_roles`, `sessions`, `auth_events` | Phase 1 | The identity spine student rows attach to. |
| Sanctum cookie sessions, 3 scopes | Phase 1 | Issue the student-scoped cookie. |
| `js/api.js` CSRF + 401-redirect helper | Phase 1 | The static auth pages call the API through it. |
| Redis rate-limit framework | Phase 1 | Server-side throttling for auth endpoints. |
| Transactional email provider (Postmark) | this phase | OTP + reset delivery; the product's front door. |

**Parallelism:** may overlap Phase 2 (content read) once the Phase 1 spine exists — disjoint tables and routes. Must **not** start before Phase 1 admin-auth/identity is green.

---

## In scope

1. Student user creation (`role=student`, `pending_verification`); sign-in issuing the student cookie; `terms_acceptances` recorded.
2. `email_verification_codes` keyed on an opaque `flow_id` (replacing `?email=`); code hashed, 10-min TTL, max 5 attempts then destroy, single-use, new send invalidates prior.
3. `password_reset_tokens` (32 CSPRNG bytes, hashed, 30–60 min, single-use, supersede-on-new) + a **new `student-reset.html`** landing page; successful reset revokes all sessions.
4. Postmark with SPF/DKIM/DMARC on a dedicated subdomain; `system.email.send` recorded against rate-limit counters and `auth_events`; bounce/complaint webhooks → suppression.
5. Server-side rate limiting on register / signin / forgot / otp-send / otp-verify (per-email + per-IP); Turnstile on the unauthenticated writes.
6. Verification-gating: unverified student may hold a session but is blocked from document upload + application submission (`must_verify` flag consumed in Phase 5).

## Out of scope (explicit)

- Student profile / documents / tracking data — [Phase 5](phase-5-student-portal-profile-document-packs-application-tracking.md).
- Partner auth — [Phase 6](phase-6-partner-identity-tenancy-agency-registration-review-sign-in-isolation.md).
- **Phone / SMS verification.** Numbers are collected and formatted but **not** verified — explicitly deferred.
- QR self-registration attribution (Phase 6/7, needs tenancy).

---

## Work breakdown

### 1. Registration + sign-in

| Task | Detail |
|---|---|
| 1.1 | `POST /api/register`: create `user` in `pending_verification`, hash password with argon2id, issue an OTP flow, return an opaque `flow_id`. **Payload must carry all five fields** — `name`, `email`, `password`, `phone` (cc+national composed), `agree`. The code sample at `js/auth.js:743` sends only `{email,password,phone}` and drops `name` + `agree`; derive the real payload from the form markup, not the comment. |
| 1.2 | Record `terms_acceptances` (document, version, accepted_at, ip, user_agent) — the `#rg-agree` checkbox is a hard requirement, stored not just validated. |
| 1.3 | **Enumeration-safe register**: the response must not reveal whether the email already exists (register is the usual enumeration leak; the reset endpoints already carry the correct guidance in code comments, register does not). |
| 1.4 | `POST /api/login`: authenticate, issue a `role=student` Sanctum cookie, apply per-account + per-IP throttling, single generic failure message for wrong-password / unknown-account / locked. `remember` (`input[name=remember]`, currently read by nobody) drives session lifetime. |
| 1.5 | Verification policy: unverified student may sign in and hold a session, but `must_verify` blocks document upload + application submission. **Client to confirm this policy before final sign-off** (open question). |

### 2. Email OTP (verify)

| Task | Detail |
|---|---|
| 2.1 | `email_verification_codes`: `{flow_id, user_id?, email, code_hash, purpose(signup_student), created_at, expires_at(10min), attempts_used, max_attempts(5), last_sent_at, consumed_at, request_ip}`. Code stored **hashed**, never plaintext. |
| 2.2 | `POST /api/verify` (unified `/auth/otp/verify`): payload `{flow_id, code}` — **not** `{email, code}`. Constant-time compare against the hash; max 5 attempts then destroy the code; 10-min expiry enforced; single-use consumption. Currently `js/auth.js:1137` accepts any six digits — this kills that. |
| 2.3 | `POST /api/verify/resend` (`/auth/otp/resend`): payload `{flow_id}`. Issue a fresh code and **invalidate the previous one** (the UI at `js/auth.js:978` already promises "the last one no longer works" — make it true). Hard per-email send cap. |
| 2.4 | **`flow_id` replaces `?email=` everywhere.** The query string is the flow's only state carrier today (`query()` at `js/auth.js:505`) and it leaks the address into history, referrers and logs. Keep `maskEmail` (`js/auth.js:516`) for display only. |

### 3. Password reset (incl. the missing landing page)

| Task | Detail |
|---|---|
| 3.1 | `password_reset_tokens`: `{user_id, token_hash(32 CSPRNG bytes), requested_for_email, expires_at(30–60min), consumed_at, requested_ip, consumed_ip, invalidated_by}`. Token hashed at rest. |
| 3.2 | `POST /api/password/reset` (request): payload `{email}`. Fire-and-forget, **enumeration-safe** — the confirmation reads identically whether or not the address is on file (`js/auth.js:866` already specifies this). Always `202`. Throttle per-email **and** per-IP. |
| 3.3 | Resend uses the **same** endpoint/payload; only the UI message differs. Client cooldown (`COOLDOWN_SECONDS=30`, `js/auth.js:502`) is cosmetic — the server enforces its own minimum interval + hourly cap. |
| 3.4 | **Build `student-reset.html`** (new page, sibling of `student-forgot.html`, reached from the emailed link). Token in URL fragment/query + new password + confirm + expiry/used error states. |
| 3.5 | `POST /api/password/reset/submit` (consume): verify token hash + expiry + unused, enforce the password policy server-side, **revoke ALL of the user's sessions**, invalidate every other outstanding reset token, write an `auth_event`. |

### 4. Email delivery

| Task | Detail |
|---|---|
| 4.1 | Postmark integration; SPF + DKIM + DMARC on a dedicated sending subdomain. Verify DKIM lands in a staging mailbox **early** — deliverability is the whole flow's front door. |
| 4.2 | `system.email.send` records every send against the rate-limit counters and `auth_events`. |
| 4.3 | Bounce/complaint webhooks feed a suppression list; a suppressed address stops further sends and surfaces in ops. |

### 5. Rate limiting + bot mitigation

| Task | Detail |
|---|---|
| 5.1 | Redis counters on register / signin / forgot / otp-send / otp-verify, **per-email and per-IP**, independent of client cooldowns (which reset on reload). |
| 5.2 | OTP-send: max 3 sends per email per hour, hard 30s minimum between sends (server mirror of the visible cooldown). OTP-verify: 5 attempts then code destroyed. |
| 5.3 | Cloudflare Turnstile on the unauthenticated writes (register, forgot, resend) — single `<script>` tag, no build step. |
| 5.4 | Argon2id + dummy-hash constant-time helper so a missing user and a wrong password take the same time. Breach-list check (k-anonymity range API or local top-100k) on register + reset. |

---

## Deliverables

- `login.html` register + sign-in wired to real endpoints with an enumeration-safe register response.
- `student-verify.html` driven by `flow_id`, real OTP that rejects wrong codes and destroys the code after 5 attempts.
- `student-forgot.html` + the **new `student-reset.html`** completing the reset loop.
- Postmark sending live OTP + reset emails with valid domain auth; suppression on bounce.
- Server-side rate limits + Turnstile on all unauthenticated auth writes.

---

## Security work

| Item | Requirement |
|---|---|
| OTP | CSPRNG, hashed at rest, 10-min TTL, 5-attempt cap then destroy, single-use, prior-code invalidation. Kills the "any six digits pass" defect and the PII-in-URL leak. |
| Password storage | argon2id (per Phase 1 params) + dummy-hash enumeration safety. |
| Enumeration safety | Uniform response **and** timing on register AND forgot; single generic sign-in failure for every cause. |
| Throttling | Server-side per-email + per-IP ends free email amplification and 6-digit brute force. |
| Reset token | 32 CSPRNG bytes, hashed, single-use, supersede-on-new; a successful reset revokes every session + invalidates other tokens. |
| Transport | `Cache-Control: no-store` on authed responses; keep `<meta name="robots" content="noindex">` on all six auth pages. Email never in URL / history. |
| Breach list | Reject known-breached passwords on register + reset (advisory strength meter never gates server-side). |

See [Security & compliance](../05-security-and-compliance.md) for the OTP, reset-token, and rate-limit policy specs, and [API contract](../04-api-contract.md) for the exact request/response shapes.

---

## Testing

| Test | Asserts |
|---|---|
| OTP correctness | Wrong code rejected; 6th attempt destroys the code; expiry enforced; resend invalidates the prior code — all against real Redis/DB. |
| Enumeration | Register and forgot return identical responses **and** timing for existing vs unknown emails. |
| Rate limit survives reload | Server-enforced limits hold after a page reload (unlike the old client cooldown). |
| Reset lifecycle | Token single-use; superseded-on-new; a successful reset logs the user out everywhere (all sessions revoked). |
| No PII in URL | No email appears in any URL, query string, referrer or history across verify + reset (flow_id only). |
| Deliverability | OTP + reset land in a staging mailbox with a valid DKIM signature. |
| Turnstile | An unauthenticated write without a valid Turnstile token is rejected. |

Frontend behaviour verified headless-Chrome-over-CDP with real key events for the OTP boxes (`rawKeyDown` + `char`, per [memory.md](../../memory.md) landmine — `keyDown` with text double-inserts).

---

## Exit gate

Phase is DONE only when every box is demonstrably true in staging and the named tests are green in CI.

- [ ] A student can register, receive a real OTP, verify (wrong codes rejected), sign in, and complete a password reset via `student-reset.html` end-to-end on staging.
- [ ] No email address appears in any URL, query string, referrer, or history for the verify/reset flows (`flow_id` only).
- [ ] OTP-send and verify are server-rate-limited; brute-force and email-amplification tests fail to break them.
- [ ] register and forgot are enumeration-safe (uniform response + timing) — verified by test.
- [ ] A successful password reset revokes all prior sessions.
- [ ] Postmark delivers OTP + reset with valid DKIM; a bounced address is suppressed.
- [ ] Demo disclaimers removed from `login.html` / `student-verify.html` / `student-forgot.html` as each flow goes live.
- [ ] No BLOCK-class CI gate red (secrets, fixable Critical/High CVE, SAST-high, tenancy test, licenses).
- [ ] Two-party sign-off: Architecture/DevSecOps lead (security + gate) and client product owner (demonstrable outcome).

---

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Email deliverability is the product's front door; DKIM/DMARC misconfig blocks the entire flow. | Configure SPF/DKIM/DMARC on a dedicated subdomain and verify in staging **early** (task 4.1), before wiring the client. |
| The reset landing page is easy to miss when scoping — "forgot" already looks finished. | `student-reset.html` is an explicit deliverable and an exit-gate line; the flow is not done until token consumption works. |
| Verification-gating policy (session-for-unverified) is an open product question. | Implement the `must_verify` flag now; the client confirms the policy before final sign-off. Default: allow sign-in, block upload + submission. |
| Client cooldowns mistaken for real protection. | All limits are server-side in Redis; a test proves they survive a page reload. |

---

## Frontend wiring in this phase

All four `js/auth.js` `REAL REQUEST` markers are wired this phase.

| Marker | Line | Endpoint | Flow |
|---|---|---|---|
| REAL REQUEST | `js/auth.js:738` | `POST /api/register`, `POST /api/login` | Register + sign-in |
| REAL REQUEST | `js/auth.js:859` | `POST /api/password/reset` | Forgot-password request (+ resend, shared) |
| REAL REQUEST | `js/auth.js:965` | `POST /api/verify/resend` | OTP resend |
| REAL REQUEST | `js/auth.js:1115` | `POST /api/verify` | OTP check |

New page: **`student-reset.html`** (built + wired to `POST /api/password/reset/submit`).

Pages touched: `login.html`, `student-forgot.html`, `student-verify.html`, `student-reset.html` (new). Per-page demo disclaimers (`.sa-demo`, `login.html:311`) removed as each flow goes live.

`REAL REQUEST` inventory after this phase: 6 remaining (all in `js/partner-auth.js`) + 1 in `js/portal.js`.
