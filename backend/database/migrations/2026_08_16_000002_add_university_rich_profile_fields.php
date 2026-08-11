<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8+ — the remaining editorial blocks for the full university detail
 * template: overview stat tiles, multiple ranking cards, intake accordion,
 * a cost-of-attendance table, per-level admission requirements (tabs),
 * placement job/salary rows and the "life at campus" service accordion.
 * All nullable — sections simply don't render until staff fill them in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->json('overview_stats_json')->nullable();   // [{value:"74%", label:"Acceptance rate"}]
            $table->json('rankings_json')->nullable();         // [{rank:"#54", by:"QS World"}]
            $table->json('intakes_json')->nullable();          // [{name:"Fall (September)", note:"…"}]
            $table->json('cost_rows_json')->nullable();        // [{label:"Annual avg PG tuition", value:"$25,000"}]
            $table->json('admissions_json')->nullable();       // [{level:"Masters", academic, english, tests}]
            $table->json('jobs_json')->nullable();             // [{profile:"Data Analyst", salary:"$80k–$110k"}]
            $table->json('services_json')->nullable();         // [{title:"Clubs & societies", body:"…"}]
            $table->string('placement_rate', 30)->nullable();  // e.g. "92%"
            $table->string('alumni_note', 190)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn([
                'overview_stats_json', 'rankings_json', 'intakes_json', 'cost_rows_json',
                'admissions_json', 'jobs_json', 'services_json', 'placement_rate', 'alumni_note',
            ]);
        });
    }
};
