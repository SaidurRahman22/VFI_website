# Phase 8 (P8) — Program Search: 100k Catalogue, Ingest, 40-Facet Query

**What this is:** the build spec for the program search the console is built around — a Postgres-first flat search table over ~300–600k rows answering free text + ~40 combinable facets, fed by a real ingest pipeline. **Audience:** a dedicated search dev (this phase parallelises with [Phase 7](phase-7-partner-console-core.md)).

> The engine is a solved problem at this size. The real schedule risk is that **the catalogue data source is undefined anywhere in the repo** — ingest/licensing/refresh dwarfs indexing. This phase cannot complete until the client sources the feed.

Related docs: [Phase 7 — Partner Console Core](phase-7-partner-console-core.md) · [Phase 6 — Partner Identity & Tenancy](phase-6-partner-identity-and-tenancy.md) · [Phase 9 — Money & Launch](phase-9-money-surface-staff-backoffice-and-launch.md) · [Backend master plan](../BACKEND_DEVELOPMENT_PLAN.md) · [agent orientation](../../memory.md)

---

## Goal

Deliver a search over ~100k programs × 3–6 intakes (~300–600k searchable rows) that answers free-text-over-program+university-name plus ~40 combinable facets with keyset pagination and sorting at p95 < 400 ms, backed by a rebuild-and-swap ingest pipeline and one served taxonomy that kills the five divergent hardcoded option lists.

## Duration

4–6 weeks — **gated by the undefined catalogue source**. Code is ~4 weeks; the gate is the feed.

## Prerequisites

| Needs | From | Why |
|---|---|---|
| Taxonomy + institution/program base migration | Early migration (schema can land in [Phase 6](phase-6-partner-identity-and-tenancy.md) or a P6-adjacent migration) | Search is otherwise independent of console CRUD and can start once these tables exist |
| Console guard + partner session | [Phase 7](phase-7-partner-console-core.md) | Search is behind the console (rate-limited per session) |
| Content-bundle seam + `js/api.js` | [Phase 2](phase-2-public-content-read-path.md) / [Phase 1](phase-1-identity-spine-and-admin-lockdown.md) | `partner-search.html` posts through the seam |
| **A committed catalogue feed** (CSV / vendor API / manual) | Client | Hard blocker — no feed, no data, no phase completion |

**Parallelisation:** almost fully independent of [Phase 7](phase-7-partner-console-core.md)'s money/console CRUD. A dedicated dev can run this concurrently **once the taxonomy + institution/program schema migration is sequenced early**. Its true blocker is the feed, not code.

---

## In scope

- Relational tables: `institutions`, `programs`, `program_intakes`, `program_requirements`, `program_labels`, `program_nationality_rules`; `taxonomy_terms` serving the vocabularies.
- `program_search` denormalised flat table (rebuild-and-swap on ingest).
- Search endpoint honouring all inputs, combinable; result cards + pagination + sort surface (built from scratch — there is none today).
- Ingest/refresh Artisan pipeline with a staleness/version flag.
- `programs.get` detail; shortlist/compare **modelled** (build if scoped with the client, else explicitly flagged — never silently dropped).

## Out of scope (explicit)

| Not here | Where / why |
|---|---|
| Live per-facet result counts | Documented exit criterion for adding Typesense; **not in v1** — the current UI shows no counts |
| `applications.create` fee mechanics | [Phase 9](phase-9-money-surface-staff-backoffice-and-launch.md) |
| A second datastore | Not unless the per-facet-count requirement later forces it (planned exit, not a rebuild) |
| Catalogue data cleansing/normalisation beyond ingest validation | Depends entirely on the feed shape the client provides |

---

## Work breakdown

### 1. Relational schema

| Table | Key columns |
|---|---|
| `institutions` | `id`, `name`, `country`, `province_state`, `city`, `is_major_city`, `has_own_english_test`, `offer_tat_band`, `offer_acceptance_band`, `affordability_band`, `tuition_deposit_policy` (`none\|low\|standard`), `interview_required`, `vfi_represented`, `logo_key` |
| `programs` | `id`, `institution_id`, `title`, `level` (16 values), `study_area` (7), `discipline_area`, `duration_band`, `esl_elp_available`, `tuition_fee_minor` + `currency`, `application_fee_minor` + `currency`, `is_stem`, `has_coop_internship`, `scholarship_available`, `application_fee_waiver`, `moi_acceptable`, `job_demand_band`, `is_open`, `updated_at` |
| `program_intakes` | `id`, `program_id`, `intake_month`, `intake_year`, `application_deadline_at`, `status` (`open\|closed\|waitlist`) |
| `program_requirements` | `id`, `program_id`, `test`, `min_overall`, `min_subscore_json`, `is_required`, `waiver_available`, `academic_min_gpa`, `maths_required` |
| `program_labels` + `program_label_map` | curated/derived tags (Scholarship, Co-op, Low Tuition, STEM, …) |
| `program_nationality_rules` | `id`, `program_id`, `nationality`, `eligible`, `notes`, `fee_override_minor` |
| `taxonomy_terms` | `id`, `vocabulary`, `code`, `label`, `sort_order`, `active` — the single source killing the 5 hardcoded copies |

### 2. `program_search` flat table + indexes

Rebuild-and-swap on ingest (build into `program_search_next`, then swap by rename inside a transaction — no empty-result window).

```sql
CREATE TABLE program_search (
  program_id       uuid PRIMARY KEY,
  institution_id   uuid NOT NULL,
  ts               tsvector NOT NULL,          -- program title + university name
  title            text NOT NULL,
  university_name  text NOT NULL,
  country          text NOT NULL,
  province_state   text,
  level            text NOT NULL,
  study_area       text,
  discipline_area  text,
  duration_band    text,
  tuition_fee_minor bigint,
  application_deadline_at date,
  offer_tat_days   int,
  -- ~30 requirement/label/quick-filter booleans packed into one bitset:
  flags            smallint[] NOT NULL,
  intake_month     smallint,
  intake_year      smallint,
  is_stale         boolean NOT NULL DEFAULT false
);
CREATE INDEX ix_ps_ts     ON program_search USING gin (ts);
CREATE INDEX ix_ps_trgm   ON program_search USING gin (title gin_trgm_ops);   -- typeahead
CREATE INDEX ix_ps_flags  ON program_search USING gin (flags);                 -- @> / && facet match
CREATE INDEX ix_ps_scalar ON program_search (country, level, intake_year, intake_month);
CREATE INDEX ix_ps_sort   ON program_search (application_deadline_at, tuition_fee_minor);
```

- Free text → `to_tsvector` over program title + university name; `pg_trgm` for typeahead.
- The ~30 requirement/label/quick-filter checkboxes → a `smallint[]` bitset; a query with N chips becomes `flags @> ARRAY[...]` (AND) or `&& ARRAY[...]` (OR) depending on facet semantics, served by one GIN index.
- **Negative-requirement flags** (Without English Proficiency / Without GRE / Without GMAT / Without Maths) are **waiver flags**, not absent rows — model them as explicit bits, or they silently mismatch.
- Keyset (not offset) pagination for stability under concurrent inserts.

### 3. Search endpoint

`GET /api/partner/programs/search` honouring, all combinable: free text, intake (12 months + All), year (2026–2028), student nationality, student state, 16 program-level checkboxes, 6 dropdown facets (country, province/state, study area, discipline area, duration, ESL/ELP), 16 requirement checkboxes, 14 quick-filter chips. Sort by deadline / tuition / offer TAT.

- Build the SQL with **parameterised/raw-safe** query builder — the facet bitset and text query must never be string-concatenated from user input.
- Return result cards + a keyset cursor. Result-card + detail markup is built here (`partner-search.html` has none today; `#pgSearchBtn` only toasts).

### 4. Ingest / refresh pipeline

- Artisan command `programs:ingest` per the client's chosen feed (CSV/vendor/manual): validate every field against the taxonomy allow-list (no injection via feed fields), upsert into the relational tables, rebuild `program_search_next`, swap atomically.
- **Staleness/version flag:** intakes past their deadline (or missing from the latest feed) are flagged `is_stale` and hidden from results, so partners never apply to a dead intake.
- Run as a privileged scheduled/queued job on the `search` queue (so it never delays an OTP or an AV scan).

### 5. Detail + shortlist/compare

- `GET /api/partner/programs/{id}` → fees, requirements, intakes, deadlines, institution profile.
- `program_shortlists` (`agency_id`, `student_id`, `program_id`, `note`) — **build only if the client scopes it**; otherwise flag it explicitly at sign-off. It is promised in copy but has no UI today; do not silently drop it.

---

## Deliverables

- A working search over a realistically-sized seeded catalogue with sub-100 ms facet filtering and keyset pagination.
- Ingest pipeline + staleness flag; served taxonomy replacing the hardcoded option lists across `partner-search.html`, `js/portal.js`, `partner-students.html`, `partner-interview.html`, `partner-dashboard.html`.
- Program result cards + detail view wired into `partner-search.html`.

## Security work

| Item | Control |
|---|---|
| Expensive query | Per-session rate limit on search (the quota system is the commercial limiter; this is the infra one) |
| Feed injection | Ingest validates every field against the taxonomy allow-list; taxonomy is allow-list, not free text |
| SQL safety | Parameterised/raw-safe builder for the facet bitset and tsquery — no string concatenation of user input |
| No PII in index | The catalogue is public; `program_search` holds no student/partner data |

## Testing

| Test | Asserts |
|---|---|
| Performance (gated) | p95 < 400 ms at real catalogue size across representative 40-facet combinations, measured in staging/CI |
| Facet correctness | Facet combinations return exactly the matching set; **negative/waiver flags behave as waivers, not absent rows** |
| Ingest atomicity | Rebuild-and-swap has no partial/empty search window; a stale intake is flagged and hidden |
| Pagination | Keyset is stable under concurrent inserts (no dupes/skips) |
| Taxonomy | One served source; the five previously-divergent hardcoded lists are gone |

---

## Exit gate

- [ ] A partner can search the catalogue by free text plus any combination of the ~40 facets, page and sort results, with **p95 < 400 ms at real row count** (measured in CI/staging).
- [ ] The taxonomy is served from one source and the five previously-divergent hardcoded lists are removed.
- [ ] An ingest run rebuilds the search table atomically with **no empty-result window** and flags stale intakes.
- [ ] Facet correctness — including negative/waiver requirement flags — is proven by tests.
- [ ] Shortlist/compare is either built (if scoped) or explicitly flagged as deferred at sign-off (not silently dropped).
- [ ] No BLOCK-class CI gate red (secrets, CVE, SAST-high, licences).

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| **Catalogue data source undefined** — the real schedule risk | Treat feed sourcing as a client deliverable that gates this phase; build against a synthetic ~500k-row seed so code lands independently of the feed |
| Client later demands live per-facet counts | Planned exit: add Typesense (single Go binary) — a bolt-on, not a rebuild. Do not adopt it pre-emptively |
| Shared taxonomy/institution schema couples P8 to P6/P7 | Sequence that migration early so the dedicated search dev is unblocked |
| p95 regresses as the catalogue grows an order of magnitude | Review trigger in the ADR: sustained p95 > 400 ms → re-evaluate search infra |

## Frontend wiring (exact files/pages)

No REAL REQUEST markers in this phase.

| File / page | Change |
|---|---|
| `partner-search.html` `#pgSearchBtn`, `.pg-search__searchrow`, `.pg-search__cols`, `.pg-search__chips` | Wire the real search endpoint; add result cards + keyset pagination |
| `partner-search.html` option lists | Replaced by served `taxonomy_terms` |
| `js/portal.js` `COUNTRIES` / `DESTS` / `opts()` | Replaced by served taxonomy (kills a divergent copy) |
| `partner-students.html`, `partner-interview.html`, `partner-dashboard.html` option lists | Replaced by served taxonomy (kills the remaining copies) |
| Program detail view | New markup on `partner-search.html`; `programs.get` wired |
| Shortlist/compare | Wired **only if scoped** with the client |
