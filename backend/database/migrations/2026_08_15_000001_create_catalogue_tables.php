<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8A — the program catalogue (docs §1). Public reference data (no PII):
 * institutions + programs + intakes + requirements + labels + nationality rules,
 * plus `taxonomy_terms` as the SINGLE served vocabulary that kills the five
 * divergent hardcoded option lists. Every row carries `source` (`seed` for the
 * flagged placeholder data, or the real feed name) so seeded rows swap out for a
 * licensed feed with no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Reuse the EXISTING `taxonomy_terms` (Phase 3, kind/value/label/position)
        // as the single served vocabulary — just add an `active` flag so search
        // vocabularies can be retired without deletion (docs §1).
        Schema::table('taxonomy_terms', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('position');
            $table->index(['kind', 'active']);
        });

        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country', 90);
            $table->string('province_state', 90)->nullable();
            $table->string('city', 90)->nullable();
            $table->boolean('is_major_city')->default(false);
            $table->boolean('has_own_english_test')->default(false);
            $table->string('offer_tat_band', 20)->nullable();       // fast | standard | slow
            $table->string('offer_acceptance_band', 20)->nullable(); // high | medium | low
            $table->string('affordability_band', 20)->nullable();    // low | medium | high
            $table->string('tuition_deposit_policy', 20)->default('standard'); // none | low | standard
            $table->boolean('interview_required')->default(false);
            $table->boolean('vfi_represented')->default(false);
            $table->string('logo_key')->nullable();
            $table->string('source', 40)->default('seed');
            $table->string('external_ref', 120)->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_ref']);
            $table->index('country');
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('title');
            $table->string('level', 60);                 // taxonomy: bachelor, master, phd, …
            $table->string('study_area', 60)->nullable();
            $table->string('discipline_area', 90)->nullable();
            $table->string('duration_band', 30)->nullable();  // e.g. 1yr | 2yr | 3yr+
            $table->boolean('esl_elp_available')->default(false);
            $table->unsignedBigInteger('tuition_fee_minor')->nullable();   // minor units (cents)
            $table->string('tuition_currency', 3)->nullable();
            $table->unsignedBigInteger('application_fee_minor')->nullable();
            $table->string('application_fee_currency', 3)->nullable();
            $table->boolean('is_stem')->default(false);
            $table->boolean('has_coop_internship')->default(false);
            $table->boolean('scholarship_available')->default(false);
            $table->boolean('application_fee_waiver')->default(false);
            $table->boolean('moi_acceptable')->default(false);       // Medium of Instruction accepted
            $table->string('job_demand_band', 20)->nullable();
            $table->boolean('is_open')->default(true);
            $table->string('source', 40)->default('seed');
            $table->string('external_ref', 120)->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_ref']);
            $table->index(['institution_id', 'level']);
        });

        Schema::create('program_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->unsignedTinyInteger('intake_month');      // 1..12
            $table->unsignedSmallInteger('intake_year');
            $table->string('season_label', 20)->nullable();   // Spring | Summer | Fall/Autumn | Winter
            $table->date('application_deadline_at')->nullable();
            $table->string('status', 12)->default('open');    // open | closed | waitlist
            $table->timestamps();
            $table->unique(['program_id', 'intake_month', 'intake_year']);
            $table->index(['intake_year', 'intake_month']);
        });

        Schema::create('program_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('test', 30);                       // ielts | toefl | pte | gre | gmat | duolingo
            $table->decimal('min_overall', 5, 2)->nullable();
            $table->json('min_subscore_json')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('waiver_available')->default(false);   // the NEGATIVE/waiver flag
            $table->decimal('academic_min_gpa', 4, 2)->nullable();
            $table->boolean('maths_required')->default(false);
            $table->timestamps();
            $table->index('program_id');
        });

        Schema::create('program_labels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('label', 120);
            $table->timestamps();
        });

        Schema::create('program_label_map', function (Blueprint $table) {
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('program_label_id')->constrained('program_labels')->cascadeOnDelete();
            $table->primary(['program_id', 'program_label_id']);
        });

        Schema::create('program_nationality_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('nationality', 90);
            $table->boolean('eligible')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('fee_override_minor')->nullable();
            $table->timestamps();
            $table->index(['program_id', 'nationality']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_nationality_rules');
        Schema::dropIfExists('program_label_map');
        Schema::dropIfExists('program_labels');
        Schema::dropIfExists('program_requirements');
        Schema::dropIfExists('program_intakes');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('institutions');
        Schema::table('taxonomy_terms', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};
