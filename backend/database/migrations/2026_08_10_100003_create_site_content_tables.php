<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B — the override singletons + maps as a key→JSONB store (docs §2),
 * plus a taxonomy lookup. One row per key:
 *   settings, countries, regions, servicesPage, partnerPage, partnerConsoleText,
 *   media (key→imgId), pages (file→bool).
 * `version` supports P3 optimistic concurrency. Values round-trip ""/[] faithfully.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_content', function (Blueprint $t) {
            $t->id();
            $t->string('key', 64)->unique();
            $t->json('value')->nullable();     // jsonb on pgsql, json/text on sqlite/mysql
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('taxonomy_terms', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 40)->index();    // e.g. blog_category, event_type, country
            $t->string('value', 120);
            $t->string('label', 160)->nullable();
            $t->integer('position')->default(0);
            $t->timestamps();
            $t->unique(['kind', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_content');
        Schema::dropIfExists('taxonomy_terms');
    }
};
