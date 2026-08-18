<?php

namespace App\Services\Ingest;

/**
 * Phase 8C — a catalogue ingest source. Each source yields NORMALISED records
 * (an institution + a program + its intakes + requirements) that the ingest
 * command validates against the taxonomy allow-list and upserts. Real feeds
 * (US/DE) and the synthetic seed all implement this one shape, so the pipeline
 * is source-agnostic.
 *
 * A record is an associative array:
 * [
 *   'institution' => ['name','country','province_state'?,'city'?,'vfi_represented'?,
 *                     'is_major_city'?,'has_own_english_test'?,'offer_tat_band'?,
 *                     'offer_acceptance_band'?,'affordability_band'?,
 *                     'tuition_deposit_policy'?,'interview_required'?,'external_ref'],
 *   'program'     => ['title','level','study_area'?,'discipline_area'?,'duration_band'?,
 *                     'tuition_fee_minor'?,'tuition_currency'?,'application_fee_minor'?,
 *                     'tuition_basis'? ('programme'|'institution_average' — say
 *                       'institution_average' when the feed publishes one figure
 *                       per school rather than per course; defaults to 'programme'),
 *                     'application_fee_currency'?,'is_stem'?,'has_coop_internship'?,
 *                     'scholarship_available'?,'application_fee_waiver'?,'moi_acceptable'?,
 *                     'esl_elp_available'?,'job_demand_band'?,'is_open'?,'external_ref'],
 *   'intakes'     => [['month'=>int,'year'=>int,'season'=>string,'deadline'=>?string,'status'=>string], …],
 *   'requirements'=> [['test'=>string,'min_overall'=>?float,'is_required'=>bool,
 *                     'waiver_available'=>bool,'maths_required'?=>bool], …],
 * ]
 */
interface IngestSource
{
    /** Machine name stored on every ingested row (e.g. seed | scorecard | daad). */
    public function name(): string;

    /** @return iterable<array> normalised records */
    public function records(): iterable;
}
