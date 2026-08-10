# Phase 3 — Admin CMS Write Path (Filament): Content, Media, Pages, Backup, Audit

Build spec for the phase that gives staff a real authenticated CMS. For engineers and AI agents implementing the backend. Read [memory.md](../../memory.md) and the [phase index](README.md) first. Sibling references: [Data model](../03-data-model.md) · [API contract](../04-api-contract.md) · [Security & compliance](../05-security-and-compliance.md).

---

## Goal

Turn the read-only content path from [Phase 2](phase-2-public-content-read-path-store-js-seam-contact-form-persistence.md) into a full authenticated **write** path: content CRUD with reorder, the server-side image upload/downscale pipeline, page-visibility management, and safe backup/restore — all behind the [Phase 1](phase-1-identity-spine-admin-lockdown.md) admin auth, every mutation audited, with a `content_editor` vs `owner` role split.

## Duration

4–5 weeks.

## Prerequisites

| Needs | From | Why |
|---|---|---|
| Admin auth + mandatory TOTP | Phase 1 | No admin write endpoint may exist before login exists. **Hard dependency.** |
| `users`, `user_roles`, `auth_events` | Phase 1 | Role split + audit actor. |
| Filament panel behind admin auth | Phase 1 | This phase fills the panel with resources. |
| Content tables + importer + bundle read | Phase 2 | CMS writes the same tables the public bundle reads. |
| R2 public bucket + server-generated-key convention | Phase 0 | Image pipeline target. |
| `content_audit_log` table | this phase (new migration) | Before/after record for every mutation. |

---

## In scope

1. Filament resources for all 10 collections (`events`, `blogs`, `news`, `photos`, `ppManagers`, `ppUpdates`, `ppQuicklinks`, `ppDocs`, `ppEmails`, `ppNotifs`) mapping the existing `SCHEMA` in `js/admin.js` 1:1.
2. Reorderable rows (explicit `position` INTEGER), SCHEMA-driven forms, soft-delete, PATCH-merge update semantics.
3. Whole-list-replace editors for the 5 override singletons (country lists, region bands, service blocks, partner-page lists) **with optimistic concurrency**.
4. Server-side image pipeline (magic-byte sniff → re-encode/downscale → EXIF strip → R2 → content-hashed URL).
5. Media-slot registry + `setMedia` with **reference-counted** image deletion.
6. Page-visibility management as a fixed allow-list catalogue.
7. Role split enforced by policies: `content_editor` vs `owner`/`superadmin`.
8. `content_audit_log` (append-only, before/after) on every write; server-side backup snapshot + guarded restore.

## Out of scope (explicit)

- Student / partner identity and data — [Phase 4](phase-4-student-identity-registration-sign-in-otp-password-reset.md), [Phase 5](phase-5-student-portal-profile-document-packs-application-tracking.md), Phase 6.
- **Draft / scheduling / preview workflow.** New product surface, not a port. The audit log + revert is the recovery mechanism instead. Do not build unless separately scoped.
- Partner-console content becoming per-agency. Stays global here; `partnerName` identity moves to session in Phase 6.
- Any public-actor `REAL REQUEST` wiring in `js/auth.js` / `js/partner-auth.js`.

---

## Work breakdown

### 1. Collection resources (Filament) — the 10 lists

| Task | Detail |
|---|---|
| 1.1 | One Filament resource per collection; form fields derived from `SCHEMA[kind]` in `js/admin.js` (titleKey, metaKeys, image slot). Keep field names identical so the Phase 2 bundle shape is unchanged. |
| 1.2 | **Create defaults to front.** `VFI.put()` unshifts today; a new row must get the lowest `position` so it becomes the home featured event / `blogs[0]` featured card. Assign `position = MIN(position) - 1` (or a reorder-normalise) inside a transaction. |
| 1.3 | **Update = PATCH-merge**, not replace. Merge form fields over the stored row so fields outside `SCHEMA` survive (mirrors `openForm` reading `VFI.get(kind,id)` first). |
| 1.4 | Soft-delete (`deleted_at`). On delete, cascade-check `imgId` via the reference counter (task 5.3) before touching image storage. |
| 1.5 | Reorder UI (Filament reorderable table / up-down) writing `position`; persist atomically. |
| 1.6 | `blogs` keeps `legacy_id` (public URL key) immutable and non-editable in the form. Body stays **plain text** — server rejects HTML (see security). |
| 1.7 | `photos` empty state is a real state (gallery shows "no photos yet"); do not seed fall-through content. Bulk multi-file upload path reuses the image pipeline sequentially with per-file progress. |

### 2. Override-singleton editors (the 5 jsonb objects)

| Task | Detail |
|---|---|
| 2.1 | Editors for `countries{6}`, `regions{europe,asia}`, `servicesPage`, `partnerPage`, `partnerConsoleText`. Text fields merge (key-merge, never clobber sibling lists). |
| 2.2 | **Whole-list replace** for the embedded lists (country universities/scholarships/salaries/faqs, region bands, service blocks, partner features/steps/testimonials/jobs/faqs). Rows where every field is empty are dropped (matches `collectRows`); saving an empty list is a deliberate "clear → page keeps its own copy". |
| 2.3 | **Optimistic concurrency**: each singleton carries a `version` (or `updated_at`). Save sends the version it loaded; a stale version is rejected `409`, never silently overwritten. |
| 2.4 | Empty-string / `[]` round-trips faithfully — `ConvertEmptyStringsToNull` + `TrimStrings` must remain **off** on these routes (the Phase 0 landmine). Verify in a test, not by eye. |

### 3. Page-visibility management

| Task | Detail |
|---|---|
| 3.1 | Fixed allow-list catalogue (server-side copy of `SITE_PAGES`, 7 groups / 38 entries). `setPage` validates the filename against the catalogue and **rejects arbitrary filenames** (today `VFI.setPage` writes anything). |
| 3.2 | Six locked "Always on" entries render without a toggle; the two sign-in entry pages (`login.html`, `vfi-partner-login.html`) are edge-enforced so a toggle cannot switch off sign-in for the whole business. |
| 3.3 | Honest admin copy: this is a **menu-level toggle**, not access control (a switched-off page's HTML is still served). Where genuine hiding is intended, enforce a 410 at the edge for locked-off pages. |
| 3.4 | Every toggle writes an audit row and is `owner`/`superadmin`-only. |

### 4. Role split + policies

| Task | Detail |
|---|---|
| 4.1 | `content_editor`: collections + page text + media. `owner`/`superadmin`: backup import, reset, page on/off, admin-user management. |
| 4.2 | Laravel policies/gates on every resource + action; Filament navigation hides what the role cannot use, but the **policy is the enforcement**, not the hidden nav. |
| 4.3 | Negative test proves `content_editor` cannot import/reset/toggle pages (see testing). |

### 5. Image pipeline (server-side)

| Task | Detail |
|---|---|
| 5.1 | `browser → API (multipart) → magic-byte sniff → re-encode/downscale → EXIF strip → R2 public bucket → content-hashed immutable URL`. Server generates the id/key; never trust the client filename. |
| 5.2 | Per-slot dimension/quality params preserved from current call sites: covers 1400/0.82, gallery 1600/0.82, home slots 1200/0.84, country slots 1600/0.82, partner slots 1400/0.84, repeater rows 1200/0.82. Re-encode to JPEG (flattens PNG transparency to white, as today). Raw-size cap (few MB) + real content-type revalidation. |
| 5.3 | **Reference-counted deletion.** Before deleting an `imgId`, check no other collection row or media slot references it. Path-style ids (`assets/img/*.jpg`, contains `/`, `^https?:`, image-extension) resolve to themselves and `delImage` on them is a **no-op** — never touch a bundled static file. |
| 5.4 | `getImage()` dual-mode preserved (real ids → CDN URL; path/URL ids pass through) — unchanged from Phase 2, restated here because upload now mints new ids. |
| 5.5 | Orphan-image sweep scheduled job (covers repeater-row uploads abandoned before save, which the current per-form bookkeeping never cleans). |

### 6. Media-slot registry

| Task | Detail |
|---|---|
| 6.1 | `media{key→imgId}` map with the known keys: 8 home slots, 2 partner slots, `country_<slug>_<14>`. `setMedia(key, imgId)` auto-deletes the previous image **via the reference counter** (task 5.3), not blindly. |
| 6.2 | Clearing a slot (`setMedia(key, null)`) restores the placeholder and deletes the image (reference-checked). |

### 7. Backup / restore + audit

| Task | Detail |
|---|---|
| 7.1 | `content_audit_log`: append-only, `{actor_user_id, action(create\|update\|delete\|reorder\|import\|reset\|toggle_page), entity, entity_id, before_json, after_json, at, ip}`. Written inside the same transaction as every mutation. REVOKE UPDATE/DELETE from the app DB role. |
| 7.2 | Server-side backup snapshot to R2 (separate prefix, retention). Export shape compatible with the current `exportAll` JSON so the escape-hatch download still round-trips. |
| 7.3 | **Guarded restore**: owner/superadmin-only, strict schema validation, size cap, **automatic pre-restore snapshot**, audit entry. No more single-field (`payload.content` exists) trust. |
| 7.4 | `reset-to-demo` behind a build flag or removed from the production build entirely (it is a demo affordance that orphans images). |

---

## Deliverables

- Working Filament CMS for all 10 collections with drag / up-down reorder and a full audit trail.
- Concurrency-safe whole-list saves for the 5 override singletons (stale save rejected, not clobbered).
- Real image upload producing CDN-served content-hashed images; orphan-image sweep scheduled.
- Media-slot registry with reference-counted deletion.
- Page on/off toggle enforced against a server-side allow-list, audited, `owner`-only.
- Owner-only backup export/import with pre-restore snapshot and schema validation.
- `content_audit_log` populated on every create/update/delete/reorder/import/toggle.

---

## Security work

| Item | Requirement |
|---|---|
| Authorisation | Every write role-gated by policy (`content_editor` vs `owner`). Backup import / reset / page-toggle are `owner`/`superadmin`-only, audited, size-capped, schema-validated. |
| Backup import hardening | Owner-only whole-site overwrite: strict validation, size cap, automatic pre-restore snapshot, audit entry. Treat as the highest-blast-radius endpoint in the phase. |
| Upload hardening | Magic-byte validation (not extension / `accept`), re-encode, raw-size cap, reference-counted deletion (no cross-reference asset loss, no static-file deletion). |
| URL-scheme allow-list | Re-enforce `http/https/mailto/relative` on all admin-authored URL fields at write time: `ppQuicklinks.url`, `ppDocs.url`, `services_blocks.ctaHref`. A `javascript:`/`data:` value is rejected server-side. |
| Blog body contract | Plain text end-to-end; server validates and stores as plain text (`## `→h2, `- `→li, `> `→quote parsed client-side). No HTML — preserves the anti-stored-XSS contract. |
| Page toggle | Privileged, audited; the two sign-in pages cannot be switched off (business-wide DoS lever). |
| Audit | `content_audit_log` append-only; app role has no UPDATE/DELETE grant on it. |

See [Security & compliance](../05-security-and-compliance.md) for the URL allow-list and audit-log specifications.

---

## Testing

| Test | Asserts |
|---|---|
| CRUD + reorder | New item lands at front; reorder persists; home featured trio reflects `position`; `blogs[0]` is the featured card. |
| Concurrency | Two editors on the same override list — the second (stale-version) save is rejected `409`, first editor's rows survive. |
| Upload — magic byte | A renamed `.exe`/`.svg` carrying an image extension is rejected by the byte sniff. |
| Upload — reference count | A shared `imgId` (e.g. seeded `assets/img/city-uk.jpg` reused across rows) is **not** deleted while still referenced; `delImage` on a path-style id no-ops. |
| Backup round-trip | export → import restores content identically; a malformed / oversized payload is rejected and a pre-restore snapshot exists. |
| Authz | `content_editor` cannot import/reset/toggle pages (403); every mutation writes exactly one audit row with before/after. |
| Empty-string contract | A blanked override field/list round-trips as `""`/`[]` and the public page keeps its built-in HTML (regression guard on the disabled middleware). |
| Page allow-list | `setPage("evil.html", false)` is rejected; only catalogue filenames are writable. |

Testing method is headless-Chrome-over-CDP for any frontend-visible behaviour (see [memory.md](../../memory.md) §Testing); backend assertions run in CI (PHPUnit/Pest).

---

## Exit gate

Phase is DONE only when every box is demonstrably true in staging and the named tests are green in CI.

- [ ] Staff can create / edit / delete / reorder all 10 content types behind admin auth, and every mutation produces a before/after row in `content_audit_log`.
- [ ] A stale whole-list save is rejected by optimistic concurrency (demonstrated live with two sessions).
- [ ] A malicious upload (wrong magic bytes) is rejected; a malformed / oversized backup import is rejected; a pre-restore snapshot is created before any restore.
- [ ] `content_editor` is provably unable to import / reset / toggle pages (automated 403 test); page-toggle writes only allow-listed filenames.
- [ ] Deleting content does not delete a still-referenced shared image (reference-count test green); `delImage` on a path-style id never touches a static file.
- [ ] A `javascript:`/`data:` URL in any admin-authored URL field is rejected server-side; blog body is stored and rendered as plain text.
- [ ] Public pages reflect real admin edits through the Phase 2 bundle with the empty-means-fall-through contract intact.
- [ ] No BLOCK-class CI gate red (secrets, fixable Critical/High CVE, SAST-high, tenancy test, licenses).
- [ ] Two-party sign-off: Architecture/DevSecOps lead (security + gate) and client product owner (demonstrable outcome).

---

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Filament repeaters over Dhaka latency (Livewire) feel sluggish on big lists. | Paginate lists; set expectations; keep whole-list editors to the genuinely small override lists. |
| Backup import is a whole-site overwrite primitive — high blast radius even hardened. | Owner-only + MFA + audited + automatic pre-restore snapshot; plan a two-step diff-preview as a later increment. |
| Reference-counting deletion mishandles path-style bundled assets. | Explicit no-op branch for path/URL ids in `delImage`; covered by a dedicated test. |
| Empty-string middleware silently re-enabled in a later refactor. | Regression test on the content routes asserts `""`/`[]` round-trip; fails CI if a default is substituted. |
| Orphan images from abandoned repeater-row uploads accumulate in R2. | Scheduled orphan sweep; document staging-then-commit as the v2 upload model. |

---

## Frontend wiring in this phase

No public-actor `REAL REQUEST` markers are wired here (those are Phases 4/6). This phase is **admin-side only**.

| File / surface | Change |
|---|---|
| `js/admin.js` | The Filament CMS now writes the same content that the Phase 2 `GET /content/bundle` reads. The legacy `admin.html` editor is superseded by the authenticated Filament panel. |
| `js/render.js` / `js/portal-render.js` | Unchanged — they already read the bundle; public/console pages now reflect real edits. |
| Marketing pages | Per-page demo-disclaimer removal **begins** for content-driven surfaces now backed by real editing. |

`REAL REQUEST` markers touched: **none.** (Inventory unchanged: 4 in `js/auth.js`, 6 in `js/partner-auth.js`, 1 in `js/portal.js`, all still stubbed.)
