<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8+ — editorial "university profile" fields on institutions. These are
 * authored by staff in the admin (Filament) and power the rich public detail
 * page (Overview / Ranking / Cost / Scholarships / Admissions / Placements /
 * Gallery / FAQs) on top of the ingested catalogue (intakes/courses come from
 * programs). All nullable — a university renders fine with none of them set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->string('website', 190)->nullable()->after('logo_key');
            $table->string('hero_image_key', 190)->nullable()->after('logo_key');
            $table->string('tagline', 190)->nullable()->after('name');

            $table->text('overview')->nullable();                 // rich "about" text
            $table->string('ranking_world', 60)->nullable();      // e.g. "#54"
            $table->string('ranking_national', 60)->nullable();
            $table->text('ranking_note')->nullable();

            $table->text('cost_note')->nullable();                // tuition/cost narrative
            $table->string('living_cost_note', 190)->nullable();
            $table->string('accommodation_note', 190)->nullable();

            $table->text('admission_academic')->nullable();
            $table->text('admission_english')->nullable();

            $table->text('placement_note')->nullable();
            $table->string('salary_note', 190)->nullable();

            // repeatable editorial content
            $table->json('scholarships_json')->nullable();        // [{name, level, amount, note}]
            $table->json('faqs_json')->nullable();                // [{q, a}]
            $table->json('gallery_json')->nullable();             // [image keys/urls]
            $table->json('recruiters_json')->nullable();          // [{name}]
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn([
                'website', 'hero_image_key', 'tagline', 'overview',
                'ranking_world', 'ranking_national', 'ranking_note',
                'cost_note', 'living_cost_note', 'accommodation_note',
                'admission_academic', 'admission_english',
                'placement_note', 'salary_note',
                'scholarships_json', 'faqs_json', 'gallery_json', 'recruiters_json',
            ]);
        });
    }
};
