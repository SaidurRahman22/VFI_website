# Phase 5 — Student Portal: Profile, Document Packs, Application Tracking

Build spec for the phase that turns the seeded student portal into real, self-scoped data. For engineers and AI agents implementing the backend. Read [memory.md](../../memory.md) and the [phase index](README.md) first. Sibling references: [Data model](../03-data-model.md) · [API contract](../04-api-contract.md) · [Security & compliance](../05-security-and-compliance.md).

---

## Goal

Replace `js/student-portal.js`'s localStorage demo (one seeded student, `vfi_student_profile`) with real self-scoped data: the seven profile cards, the two document packs on a **scan-gated private-storage pipeline** (the largest single build item in the student domain), and read-only application tracking — with the two static portal pages guarded by the API, because they cannot protect themselves.

## Duration

5–6 weeks.

## Prerequisites

| Needs | From | Why |
|---|---|---|
| Student auth + session + `must_verify` flag | Phase 4 | The portal is behind the student cookie; upload gated on verification. |
| `js/api.js` (credentials, CSRF, 401-redirect) | Phase 1 | Portal pages call `/api/me` through it. |
| R2 **private** bucket + server-generated-key convention | Phase 0 | Document blobs. |
| ClamAV sidecar reachable from the app | Phase 0 | Scan-gate before any file is readable. |
| Separate upload FPM pool | Phase 0 | Multi-MB scans must not starve the marketing site. |
| `document_access_log`, `document_files`, `student_*` tables | this phase (new migrations) | — |

---

## In scope

1. `students` + `student_profiles` / `addresses` / `qualifications` / `test_scores` / `preferences` / `preference_destinations`.
2. Implicit-self endpoints (`/api/me/profile` — **no id parameter, no IDOR**) with whole-collection-replace PUT for academic / tests (keeping Cancel/Save semantics) + optimistic concurrency.
3. `document_types` (server-driven, destination-dependent for medical/police), `student_documents` with a **new `rejected` / needs-replacement status**, `document_files` (server UUID key, never the client filename), status history + access log + disclosures.
4. **The upload pipeline**: `multipart → magic-byte + size cap → ClamAV scan-gate (not readable until clean) → private R2 → status missing→uploaded`; single-use 60–300s presigned GET for download; upload = commit.
5. Read-only tracking: applications, journey stages, activity timeline, pending actions from real tables; server-computed derived values (completeness, journey %, per-status counts, `is_overdue`).
6. Portal page guard: `/api/me` on load, redirect on 401, render nothing first; real logout revoking the session; stop persisting profile in localStorage.
7. `noindex` on both portal pages; `must_verify` blocks upload/submission for unverified students.

## Out of scope (explicit)

- **Staff verification / authoring UI** (document verify/reject, application status writes). The write side of tracking is greenfield staff work, scheduled with the staff back-office in Phase 9. Until then students see `uploaded`, never `verified`.
- Partner-side student creation / attribution — [Phase 7](phase-7-partner-console-core-students-applications-enquiries-modals.md).
- Payments / deposits. Words only in note/todo copy; no student payment surface anywhere.

---

## Work breakdown

### 1. Profile (7 cards)

| Task | Detail |
|---|---|
| 1.1 | `GET /api/me/profile` returns personal + address + `qualifications[]` + `test_scores[]` + preferences (with destinations) + both document packs + completeness in one payload (the page paints seven cards from one state object). |
| 1.2 | `PUT /api/me/profile/personal` — first/middle/last/dob/nationality/phone_cc/phone/email. **Re-run every client FILTER rule server-side** (required first+last+phone+email, phone ≥ 6 digits, email regex, max-lengths 40/70/90/30/14/8). Changing email must not silently change the sign-in identity without re-verification. |
| 1.3 | `PUT /api/me/profile/address` — line1/line2/city/district/postcode/country; server sanity checks (this is the courier address for offer letters + visa papers). |
| 1.4 | `PUT /api/me/qualifications` — **whole-collection replace** (read every DOM row, drop all-empty rows, overwrite). Validate `year` = exactly 4 digits. Optimistic concurrency (`updated_at`/ETag) per section. |
| 1.5 | `PUT /api/me/test_scores` — whole-collection replace; if a test name is chosen, `overall_score` is required; store both numeric(5,2) and the raw string (must hold `7.5` and `318`). |
| 1.6 | `PUT /api/me/preferences` — destination codes[] + intake + budget_band + field_of_study. Serve the intake option list from the backend (the hardcoded Sep 2026→Sep 2027 list goes stale). |
| 1.7 | `GET /api/me/completeness` — reproduce the exact 26-item scoring (6 personal + 5 address + 3 fixed academic + 2 fixed test + 4 preferences + 6 **application** documents; `line2` and the 6 **visa** documents excluded; thresholds ≥85% good, <55% warn). Compute server-side so a future counsellor view agrees. |

### 2. Document types + checklist

| Task | Detail |
|---|---|
| 2.1 | `document_types` server-driven (replaces hardcoded `DOC_DEFS`/`VISA_DEFS`). Two packs of 6: application (`passport, transcripts, sop, lor, financials, testreport`) + visa (`offer, visaform, visafee, finproof, photo, medical`). `medical` is **destination-dependent** — the checklist can vary per destination. |
| 2.2 | `GET /api/me/documents` returns the 12 types joined with this student's status/file for each. |
| 2.3 | `student_documents`: `{student_id + document_type_id unique, status(missing\|uploaded\|verified\|rejected), file_id?, uploaded_at, verified_by?, verified_at?, rejection_reason?}`. The **`rejected` status is new** — today an unreadable passport can only sit as `uploaded` forever. |

### 3. The upload pipeline (the big build item)

| Task | Detail |
|---|---|
| 3.1 | `POST /api/me/documents/{type}` multipart. Today `js/student-portal.js:600–611` records `f.name` and **discards the File** — this is to-be-built, not to-be-wired. |
| 3.2 | Server: content-type allow-list (pdf/jpg/png — the student `<input>` has **no `accept` attr**, so the client enforces nothing) → magic-byte sniff → size cap (multi-MB phone scans expected) → **server-generated UUID storage key** (never the client filename; it is user-controlled, truncated to 120 chars, and rendered via `esc()` — traversal + XSS risk). |
| 3.3 | **ClamAV scan-gate**: file is written to private R2 but **not readable** until `scan_status = clean`. Status transitions missing→uploaded only after a clean scan. Downscale JPEG scans only — **never** re-encode PDFs. |
| 3.4 | `GET /api/me/documents/{type}/download` → single-use 60–300s presigned GET. Never expose a durable storage path. Every mint writes a `document_access_log` row. |
| 3.5 | **Upload = commit.** Collapse the draft/commit dance (`docDraft`/`visaDraft`, committed by `SAVERS.documents`/`visadocs`) — against a real API the upload is the commit; the "Save checklist" submit degrades to a no-op confirmation. |
| 3.6 | `DELETE /api/me/documents/{type}` (remove/clear) → **soft-delete** (keep blob + audit), return to `missing`; **blocked once `verified`**. |
| 3.7 | Slow-link handling: in-flight progress state (the UI jumps straight to "Uploaded" today), idempotency keys so re-picking the same file does not create duplicate blobs, and the separate FPM pool so uploads do not starve the public site. |

### 4. Application tracking (read-only)

| Task | Detail |
|---|---|
| 4.1 | `GET /api/me/tracking` — one payload: journey stages + state, `applications[]`, timeline `events[]`, pending `actions[]`, journey %. The page builds all four from module-level constants today. |
| 4.2 | Serve **enums, not presentation** (status/tone/icon keys); the client maps to label/chip/icon as it does now. Statuses: applications 6-value (`submitted\|review\|offer\|conditional\|rejected\|enrolled`); journey stages `done\|now\|todo`; timeline tone `ok\|info\|wait\|part\|bad`. |
| 4.3 | Server-compute derived values: journey % (`(done + 0.5·any-now)/total`), per-status counts, `is_overdue` **derived from `due_at`** (today `late` is a stored boolean that goes stale; one seeded todo has no date). |
| 4.4 | Client-side status filter stays client-side (list is tiny; counts come from the same payload). |
| 4.5 | Write side (create/update applications, advance stages, append timeline, verify documents) is **out of scope** — greenfield staff work in Phase 9. Tracking is read-only here. |

### 5. Portal page guard + session

| Task | Detail |
|---|---|
| 5.1 | `student-profile.html` + `student-tracking.html` call `/api/me` on load and **redirect on 401, rendering nothing before it resolves**. They are ordinary static files and can never be protected in the front end — protection is entirely the API's job. |
| 5.2 | Real logout: `renderSideNav()`'s "Log out" (`js/student-portal.js:1077`) becomes a revoke call, not a bare `<a href="login.html">`. |
| 5.3 | **Stop persisting profile in localStorage** (`vfi_student_profile`, clear-text, survives logout — a real problem on shared/cyber-café machines). Data is fetched per session, not cached to disk. |
| 5.4 | Add `<meta name="robots" content="noindex">` to both portal pages (they currently lack it). |
| 5.5 | `must_verify` (from Phase 4) blocks upload + application submission for an unverified student. |

---

## Deliverables

- Seven profile cards reading/writing real self-scoped data with server-side re-validation of every client FILTER rule.
- Both document packs uploading real bytes to private, virus-scanned storage with single-use signed downloads and full access logging.
- Tracking page rendering real records with server-computed completeness / journey % / overdue.
- Portal pages that render nothing until authenticated and log out for real; no profile in localStorage.

---

## Security work

| Item | Requirement |
|---|---|
| Data class | Highest-sensitivity in the product: passport, transcripts, six months of bank statements, sponsor affidavit, visa form, proof of funds, passport photo, medical/police clearance — identity + financial + health + criminal-record for one person. Private bucket, server-generated keys, encryption at rest. |
| Scan-gate | ClamAV clean **before** any presign is minted; a file is unreadable until scanned. |
| Signed URLs | Single-use, 60–300s presigned GET; never a durable/public path; every mint logged. |
| Self-scope only | Token → student, **never a path/query id**. `student_ref` (`VFI-2026-04871`, sequential + guessable) is never an access key. |
| Filename safety | Client filename never builds a storage path (traversal); re-generated server-side. Content-type allow-list server-side (inputs have no `accept`). |
| `document_access_log` | Append-only; actor + time + action on every read of a passport / bank statement / visa form / clearance. App role has no UPDATE/DELETE grant. |
| `rejected` state | Added so an unreadable passport is not stuck as `uploaded`; fields frozen post-submission (legal name, DOB) become staff change-requests, not free edits. |
| No client cache | Profile no longer persisted in localStorage; `Cache-Control: no-store` on authed responses. |

See [Security & compliance](../05-security-and-compliance.md) for the document-storage, presigned-URL, access-log and GDPR/retention specs.

---

## Testing

| Test | Asserts |
|---|---|
| IDOR | No endpoint accepts a student id; enumerating `student_ref` returns nothing for another student. |
| Malware quarantine | An EICAR test file is quarantined and **never** becomes downloadable; a document is unreadable until `scan = clean`. |
| Upload validation | Oversize / wrong content-type rejected by the server (not the missing `accept` attr). |
| Presigned URL | Expiry + single-use enforced; every download writes a `document_access_log` row. |
| Whole-collection concurrency | Two devices editing academic history — stale save rejected; all-empty rows dropped as today. |
| Guard | Portal pages render no data pre-auth (401 → redirect, nothing painted); `must_verify` blocks upload for an unverified account. |
| localStorage | Profile data is no longer written to `vfi_student_profile`; nothing survives logout on disk. |

Frontend behaviour verified headless-Chrome-over-CDP (see [memory.md](../../memory.md) §Testing); assert observable outcomes (file quarantined, 401 redirect fired, stale save 409'd), not absence of errors.

---

## Exit gate

Phase is DONE only when every box is demonstrably true in staging and the named tests are green in CI.

- [ ] A student can complete all seven profile sections and upload real files to both packs; files are virus-scanned before being retrievable and downloaded only via single-use signed URLs.
- [ ] Every document read is recorded in `document_access_log`; no document is reachable via a public / durable path.
- [ ] Portal pages render nothing until `/api/me` resolves and redirect on 401; logout revokes the session server-side.
- [ ] No student id is accepted from client input on any student endpoint (IDOR negative test green).
- [ ] An EICAR / malware upload is quarantined and never served; profile data no longer persists in localStorage.
- [ ] Whole-collection replace for academic/tests rejects a stale save (optimistic concurrency) and drops all-empty rows.
- [ ] `must_verify` blocks upload + application submission for an unverified student.
- [ ] No BLOCK-class CI gate red (secrets, fixable Critical/High CVE, SAST-high, tenancy test, licenses).
- [ ] Two-party sign-off: Architecture/DevSecOps lead (security + gate) and client product owner (demonstrable outcome).

---

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Concentration of identity + financial + health + criminal-record data for one person in one bucket. | This phase **is** the gate: ACL, signed URLs, scan-gate and access log must all be right before the first real byte. Medical/police clearance handled at the strictest tier. |
| Slow Bangladeshi mobile links; multi-MB phone scans time out or duplicate. | In-flight progress state, idempotency keys, resumable/chunked upload, and a separate FPM pool so uploads do not starve the marketing site. |
| `verified` has no author yet (staff surface deferred to Phase 9). | Ship `uploaded`/`rejected` now; students see `uploaded`, not `verified`, until the staff back-office lands — an accepted, documented gap. |
| Completeness meter ignores the visa pack — a student reads 100% while half the visa file is missing. | Reproduce the documented 26-item scoring exactly; surface the asymmetry to the client as a conscious decision (change it deliberately or keep it). |

---

## Frontend wiring in this phase

**No `REAL REQUEST` markers here** — `js/student-portal.js` contains none. The critical wiring is unmarked.

| File / surface | Change |
|---|---|
| `js/student-portal.js:600–611` | The upload change handler (which records `f.name` and **discards the File**) is replaced with real multipart upload to `POST /api/me/documents/{type}`. The most important unmarked wiring in the phase. |
| `js/student-portal.js` `initProfile()` (`:320`) | `mergeInto(clone(SEED), readSaved())` replaced with the fetched `GET /api/me/profile` payload; the seven `SAVERS` become PUT calls. |
| `js/student-portal.js` `initTracking()` (`:920`) | `STAGES`/`APPS`/`EVENTS`/`TODOS` constants replaced with `GET /api/me/tracking`. |
| `js/student-portal.js` `renderSideNav()` (`:1077`) | "Log out" becomes a real revoke call. |
| `student-profile.html` / `student-tracking.html` | Gain the `/api/me` guard, `noindex`, and lose the `vfi_student_profile` localStorage path. |

`REAL REQUEST` inventory unchanged by this phase: 6 in `js/partner-auth.js` + 1 in `js/portal.js` remain (Phases 6/7).
