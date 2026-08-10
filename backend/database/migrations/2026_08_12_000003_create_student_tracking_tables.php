<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5A — read-only application tracking (docs §4). The WRITE side (staff
 * advancing stages / statuses) is Phase 9; here the tables are populated by a
 * demo seeder and served read-only. Dates are stored as real dates so the
 * server can compute is_overdue / journey % (the JS used stale display strings
 * and a hardcoded `late` boolean).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('university', 160);
            $table->string('place', 160)->nullable();
            $table->string('course', 160)->nullable();
            $table->string('intake', 40)->nullable();
            $table->date('sent_on')->nullable();
            $table->string('status', 20);               // submitted|review|offer|conditional|rejected|enrolled
            $table->unsignedTinyInteger('pct')->default(0);
            $table->string('stage', 120)->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index('student_id');
        });

        Schema::create('student_journey_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('state', 8);                 // done | now | todo
            $table->string('when_label', 60)->nullable(); // prose ("Since 22 Apr 2026")
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index('student_id');
        });

        Schema::create('student_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_on')->nullable();
            $table->string('tone', 8);                  // ok | info | wait | part | bad
            $table->string('icon', 40)->nullable();
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index('student_id');
        });

        Schema::create('student_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('icon', 40)->nullable();
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->date('due_at')->nullable();          // null = "Opens after …" (never overdue)
            $table->boolean('done')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_actions');
        Schema::dropIfExists('student_timeline_events');
        Schema::dropIfExists('student_journey_stages');
        Schema::dropIfExists('student_applications');
    }
};
