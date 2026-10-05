<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which intake the counsellor actually picked.
 *
 * A shortlist row held a student and a programme and nothing about WHEN. The
 * console showed the programme's next intake instead, and the Apply button
 * posted that same next intake into the application. So a counsellor who looked
 * at a Master's offering Fall 2026, Spring 2027 and Summer 2027 and decided on
 * Summer had nowhere to record it — the row said "this programme", the screen
 * said "Fall 2026", and the application was created for Fall.
 *
 * It mattered more than it looks, because a student carries an intake of their
 * own (students.intake_month / intake_year, which PipelineService inherits into
 * an application when nothing else is given). Two preferences, neither of them
 * the one that was chosen, and whichever won did so by accident.
 *
 * Nullable, because every existing row predates the choice and must keep
 * working: a shortlist with no chosen intake still falls back to the
 * programme's next one, exactly as it does today.
 *
 * No index. These are read one student at a time through an existing
 * (student_id, program_id) lookup; an index on a nullable preference column
 * that nothing filters on would cost writes and buy nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_shortlists', function (Blueprint $table) {
            // Same shape as students.intake_month / applications.intake_month:
            // a taxonomy season slug, not a month name.
            $table->string('intake_month', 20)->nullable()->after('program_id');
            $table->unsignedSmallInteger('intake_year')->nullable()->after('intake_month');
        });
    }

    public function down(): void
    {
        Schema::table('program_shortlists', function (Blueprint $table) {
            $table->dropColumn(['intake_month', 'intake_year']);
        });
    }
};
