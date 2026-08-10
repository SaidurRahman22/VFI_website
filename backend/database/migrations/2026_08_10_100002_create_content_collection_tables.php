<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B — the 10 content collections as relational tables (docs §1).
 * Every table carries `legacy_id` (the frontend `uid()` value, and for blogs
 * THE public URL key) and an explicit `position` (new-to-front ordering).
 * Column names are snake_case; each model's toBundle() maps them back to the
 * exact frontend keys (imgId, desc, …) so the Phase 2 bundle shape is unchanged.
 * events/blogs dates are DATE (never timestamptz — Dhaka +6 would shift the day).
 */
return new class extends Migration
{
    public function up(): void
    {
        // shared prelude for every collection table
        $base = function (Blueprint $t) {
            $t->id();
            $t->string('legacy_id', 64)->unique();
            $t->integer('position')->default(0)->index();
        };
        $tail = function (Blueprint $t) {
            $t->timestamps();
            $t->softDeletes();
        };

        Schema::create('events', function (Blueprint $t) use ($base, $tail) {
            $base($t);
            $t->string('title');
            $t->date('date')->nullable();
            $t->string('time')->nullable();
            $t->string('type')->nullable();
            $t->string('city')->nullable();
            $t->text('description')->nullable();   // → "desc"
            $t->string('color', 8)->nullable();
            $t->string('img_id')->nullable();       // → "imgId"
            $tail($t);
        });

        Schema::create('blogs', function (Blueprint $t) use ($base, $tail) {
            $base($t);
            $t->string('title');
            $t->string('category')->nullable();
            $t->date('date')->nullable();
            $t->text('excerpt')->nullable();
            $t->string('color', 8)->nullable();
            $t->string('img_id')->nullable();
            $t->string('author')->nullable();
            $t->string('read_time')->nullable();    // → "readTime"
            $t->longText('body')->nullable();        // plain text only (anti-XSS)
            $tail($t);
        });

        Schema::create('news', function (Blueprint $t) use ($base, $tail) {
            $base($t);
            $t->string('title');
            $t->string('color', 8)->nullable();
            $t->string('img_id')->nullable();
            $t->text('excerpt')->nullable();
            $tail($t);
        });

        Schema::create('photos', function (Blueprint $t) use ($base, $tail) {
            $base($t);
            $t->string('img_id')->nullable();
            $t->string('caption')->nullable();
            $t->string('alt')->nullable();
            $tail($t);
        });

        Schema::create('pp_managers', function (Blueprint $t) use ($base, $tail) {
            $base($t);
            $t->string('name');
            $t->string('role')->nullable();
            $t->string('phone')->nullable();
            $t->string('city')->nullable();
            $t->string('email')->nullable();
            $tail($t);
        });

        Schema::create('pp_updates', function (Blueprint $t) use ($base, $tail) {
            $base($t);
            $t->string('flag')->nullable();
            $t->string('title');
            $t->string('sub')->nullable();
            $t->string('date')->nullable();          // display string, not ISO
            $tail($t);
        });

        Schema::create('pp_quicklinks', function (Blueprint $t) use ($base, $tail) {
            $base($t);
            $t->string('label');
            $t->string('url')->nullable();
            $tail($t);
        });

        Schema::create('pp_docs', function (Blueprint $t) use ($base, $tail) {
            $base($t);
            $t->string('country')->nullable();
            $t->string('category')->nullable();
            $t->string('title');
            $t->string('date')->nullable();
            $t->string('size')->nullable();
            $t->string('url')->nullable();
            $tail($t);
        });

        Schema::create('pp_emails', function (Blueprint $t) use ($base, $tail) {
            $base($t);
            $t->string('subject');
            $t->string('date')->nullable();
            $tail($t);
        });

        Schema::create('pp_notifs', function (Blueprint $t) use ($base, $tail) {
            $base($t);
            $t->string('title');
            $t->text('message')->nullable();         // → "text"
            $t->string('date')->nullable();
            $tail($t);
        });
    }

    public function down(): void
    {
        foreach (['events', 'blogs', 'news', 'photos', 'pp_managers', 'pp_updates',
            'pp_quicklinks', 'pp_docs', 'pp_emails', 'pp_notifs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
