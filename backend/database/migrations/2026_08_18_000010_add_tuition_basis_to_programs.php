<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `programs.tuition_fee_minor` has been carrying two different kinds of number
 * under one name. DAAD reports a fee per course, but the U.S. College Scorecard
 * publishes ONE annual tuition per school and the ingest copied that single
 * figure onto every programme it found there — Arizona State's 399 programmes
 * all read 33139, Penn State's 394 all read 41790, and count(distinct
 * tuition_fee_minor) per scorecard institution is exactly 1. Search rendered it
 * as "Tuition", so an institution-wide average reached counsellors as this
 * programme's price, and a counsellor's screen is where a student's quote comes
 * from.
 *
 * The figure is worth keeping — an agency comparing universities wants to know
 * roughly what a year costs — so it is attributed rather than deleted.
 * `tuition_basis` records which kind of number the row holds, written by the
 * ingest source that actually knows, and is carried into `program_search` so the
 * API and the result card can qualify the figure without either of them
 * hardcoding a feed's name. The next bulk feed declares its basis the same way.
 *
 * varchar(32), not the 20 that 'institution_average' would exactly fill:
 * Postgres enforces varchar length and SQLite (tests) does not, which is how
 * content_audit_log.action 500'd in production once its vocabulary grew past its
 * width (see 2026_08_17_120000). Leave headroom for the next value.
 *
 * The backfill states a historical fact about how the existing rows were
 * written, so it keys on `source`. Sniffing the data instead ("one distinct
 * tuition per institution") would be actively wrong: DAAD institutions
 * legitimately show a single distinct value because German public universities
 * are mostly tuition-free, and they would be mislabelled as averages.
 */
return new class extends Migration
{
    public function up(): void
    {
        // hasColumn-guarded so up() is re-runnable — that is what lets a test
        // stage pre-migration rows and prove the backfill actually rewrites them.
        if (! Schema::hasColumn('programs', 'tuition_basis')) {
            Schema::table('programs', function (Blueprint $table) {
                $table->string('tuition_basis', 32)->default('programme')->after('tuition_currency');
            });
        }
        if (! Schema::hasColumn('program_search', 'tuition_basis')) {
            Schema::table('program_search', function (Blueprint $table) {
                $table->string('tuition_basis', 32)->default('programme')->after('tuition_currency');
            });
        }

        // Live data is correct the moment this runs: the 40,445 scorecard
        // programmes and the ~121k search rows built from them are relabelled in
        // place, with no re-ingest (the feed is rate-limited to ~30 requests an
        // hour) and no search-index rebuild.
        DB::table('programs')->where('source', 'scorecard')
            ->update(['tuition_basis' => 'institution_average']);
        DB::table('program_search')->where('source', 'scorecard')
            ->update(['tuition_basis' => 'institution_average']);
    }

    public function down(): void
    {
        Schema::table('program_search', function (Blueprint $table) {
            $table->dropColumn('tuition_basis');
        });
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('tuition_basis');
        });
    }
};
